<?php

namespace App\Domain\Currency;

use App\Enums\AssetOperationType;
use App\Models\AssetOperation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Custo em reais de um ativo em moeda estrangeira, operação a operação:
 * - compra/aporte: soma o valor pago convertido pelo câmbio da data (define o câmbio médio de compra);
 * - venda: baixa o custo em reais na proporção da quantidade vendida (como o PM);
 * - resgate: baixa na proporção do valor resgatado sobre o investido;
 * - desdobramento/grupamento: não muda o custo.
 */
class ForeignCostCalculator
{
    public function __construct(
        private readonly ExchangeRates $rates,
    ) {}

    /**
     * Custo em centavos de real, ou null se faltar câmbio em alguma data.
     *
     * @param  iterable<AssetOperation>  $operations
     */
    public function costBrl(iterable $operations, string $currency): ?int
    {
        $sorted = collect($operations)->sortBy([
            fn (AssetOperation $a, AssetOperation $b): int => $a->date->toDateString() <=> $b->date->toDateString(),
            fn (AssetOperation $a, AssetOperation $b): int => ($a->id ?? PHP_INT_MAX) <=> ($b->id ?? PHP_INT_MAX),
        ]);

        $costBrl = BigDecimal::zero();
        $quantity = BigDecimal::zero();
        $invested = BigDecimal::zero(); // renda fixa/previdência, na moeda

        foreach ($sorted as $operation) {
            $rate = $this->rates->rateAt($currency, $operation->date);

            if ($rate === null) {
                return null;
            }

            switch ($operation->type) {
                case AssetOperationType::Buy:
                    $paid = BigDecimal::of((string) $operation->quantity)->multipliedBy((string) $operation->unit_price)
                        ->plus(BigDecimal::ofUnscaledValue($operation->fees, 2));
                    $costBrl = $costBrl->plus($paid->multipliedBy($rate));
                    $quantity = $quantity->plus((string) $operation->quantity);
                    break;

                case AssetOperationType::Sell:
                    $sold = BigDecimal::of((string) $operation->quantity);
                    $costBrl = $quantity->isPositive()
                        ? $costBrl->minus($costBrl->multipliedBy($sold)->dividedBy($quantity, 10, RoundingMode::HalfUp))
                        : $costBrl;
                    $quantity = $quantity->minus($sold);
                    break;

                case AssetOperationType::Split:
                    $quantity = $quantity->multipliedBy((string) $operation->factor);
                    break;

                case AssetOperationType::ReverseSplit:
                    $quantity = $quantity->dividedBy((string) $operation->factor, 8, RoundingMode::HalfUp);
                    break;

                case AssetOperationType::Contribution:
                    $amount = BigDecimal::ofUnscaledValue((int) $operation->amount, 2);
                    $costBrl = $costBrl->plus($amount->multipliedBy($rate));
                    $invested = $invested->plus($amount);
                    break;

                case AssetOperationType::Withdrawal:
                    $amount = BigDecimal::ofUnscaledValue((int) $operation->amount, 2);
                    $costBrl = $invested->isPositive()
                        ? $costBrl->minus($costBrl->multipliedBy(BigDecimal::min($amount, $invested))->dividedBy($invested, 10, RoundingMode::HalfUp))
                        : $costBrl;
                    $invested = BigDecimal::max(BigDecimal::zero(), $invested->minus($amount));
                    break;
            }
        }

        return $costBrl->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();
    }
}
