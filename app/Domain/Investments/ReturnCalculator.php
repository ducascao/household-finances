<?php

namespace App\Domain\Investments;

use App\Domain\Currency\ExchangeRates;
use App\Domain\Currency\MissingExchangeRate;
use App\Enums\AssetOperationType;
use App\Models\Asset;
use App\Models\AssetIncome;
use App\Models\AssetOperation;
use App\Models\ManualValuation;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Rentabilidade por ativo e total num período [início, fim], pelo método Dietz modificado.
 *
 * - Valor inicial: posição no fim do dia anterior ao início × última cotação até lá (sem cotação, o PM).
 * - Valor final: posição no fim × última cotação até o fim (sem cotação, o PM).
 * - Renda fixa/previdência: valor pelo saldo informado até cada data (ValuationCalculator).
 * - Fluxos: compras e aportes (+) e vendas e resgates (− valor líquido recebido) dentro do período,
 *   ponderados por (dias do período − dias até o fluxo) ÷ dias do período.
 * - Proventos: valor líquido recebido no período (entram no resultado, não nos fluxos).
 * Tudo em reais: ativos em moeda estrangeira têm os valores convertidos pelo câmbio de cada data (valor inicial
 * e final nas datas do período, fluxos e proventos na data de cada um). Sem câmbio, o ativo fica de fora.
 * Usa os escopos globais: só ativos visíveis ao usuário logado.
 */
class ReturnCalculator
{
    public function __construct(
        private readonly PositionCalculator $calculator,
        private readonly PriceBook $prices,
        private readonly ValuationCalculator $valuationCalculator,
        private readonly ExchangeRates $rates,
    ) {}

    /**
     * @return array{assets: list<AssetReturn>, total: AssetReturn}
     */
    public function forPeriod(Carbon $start, Carbon $end): array
    {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->startOfDay();
        $before = $start->copy()->subDay();
        $days = (int) $start->diffInDays($end) + 1;

        $assets = Asset::query()->orderBy('type')->orderBy('name')->get();
        $ids = $assets->modelKeys();
        $valuations = ManualValuation::query()->whereIn('asset_id', $ids)->get()->groupBy('asset_id');
        $operations = AssetOperation::query()->whereIn('asset_id', $ids)->get()->groupBy('asset_id');
        $incomes = AssetIncome::query()->whereIn('asset_id', $ids)->whereBetween('date', [$start->toDateString(), $end->toDateString()])->get()->groupBy('asset_id');
        $startPrices = $this->prices->latestFor($ids, $before);
        $endPrices = $this->prices->latestFor($ids, $end);

        $total = new AssetReturn(null);
        $rows = [];

        foreach ($assets as $asset) {
            /** @var Collection<int, AssetOperation> $assetOperations */
            $assetOperations = $operations->get($asset->id, collect());

            $row = new AssetReturn($asset);
            $toBrl = fn (int $minor, Carbon $date): int => $asset->currency === 'BRL' || $minor === 0
                ? $minor
                : $this->rates->minorToBrl($minor, $asset->currency, $date);

            try {
                $untilEnd = $assetOperations->filter(fn (AssetOperation $op): bool => $op->date->lte($end));

                if ($asset->type->isValuedByBalance()) {
                    $assetValuations = $valuations->get($asset->id, collect());
                    $row->startValue = $toBrl($this->valuationCalculator->at($assetOperations, $assetValuations, $before)->value, $before);
                    $row->endValue = $toBrl($this->valuationCalculator->at($assetOperations, $assetValuations, $end)->value, $end);
                } else {
                    $row->startValue = $toBrl($this->value($assetOperations->filter(fn (AssetOperation $op): bool => $op->date->lte($before)), $startPrices->get($asset->id)?->price), $before);
                    $row->endValue = $toBrl($this->value($untilEnd, $endPrices->get($asset->id)?->price), $end);
                }

                foreach ($assetOperations->filter(fn (AssetOperation $op): bool => ($op->type->isTrade() || $op->type->isCashFlow()) && $op->date->between($start, $end)) as $operation) {
                    $cash = $toBrl(abs(ManageOperations::cashAmount($operation)), $operation->date);
                    $weight = ($days - (int) $start->diffInDays($operation->date)) / $days;

                    if ($operation->type === AssetOperationType::Buy || $operation->type === AssetOperationType::Contribution) {
                        $row->buys += $cash;
                        $row->weightedFlows += $cash * $weight;
                    } else {
                        $row->sells += $cash;
                        $row->weightedFlows -= $cash * $weight;
                    }
                }

                foreach ($this->calculator->history($untilEnd)['sales'] as $sale) {
                    if ($sale->operation->date->between($start, $end)) {
                        $row->realized += $sale->resultMinor() >= 0
                            ? $toBrl($sale->resultMinor(), $sale->operation->date)
                            : -$toBrl(-$sale->resultMinor(), $sale->operation->date);
                    }
                }

                $row->incomes = (int) $incomes->get($asset->id, collect())->sum(fn (AssetIncome $income): int => $toBrl($income->netAmount(), $income->date));
            } catch (MissingExchangeRate) {
                continue;
            }

            if ($row->isEmpty()) {
                continue;
            }

            $rows[] = $row;

            $total->add($row);
        }

        return ['assets' => $rows, 'total' => $total];
    }

    /**
     * Valor da posição em centavos: quantidade × cotação (sem cotação, o PM).
     *
     * @param  iterable<AssetOperation>  $operations
     */
    private function value(iterable $operations, ?string $price): int
    {
        $position = $this->calculator->calculate($operations);

        if (! $position->isOpen()) {
            return 0;
        }

        return $position->marketValue($price !== null ? BigDecimal::of($price) : $position->averagePrice)->getMinorAmount()->toInt();
    }
}
