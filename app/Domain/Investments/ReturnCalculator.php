<?php

namespace App\Domain\Investments;

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
 * Usa os escopos globais: só ativos visíveis ao usuário logado. Só ativos em BRL entram no total.
 */
class ReturnCalculator
{
    public function __construct(
        private readonly PositionCalculator $calculator,
        private readonly PriceBook $prices,
        private readonly ValuationCalculator $valuationCalculator,
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
            $untilEnd = $assetOperations->filter(fn (AssetOperation $op): bool => $op->date->lte($end));

            if ($asset->type->isValuedByBalance()) {
                $assetValuations = $valuations->get($asset->id, collect());
                $row->startValue = $this->valuationCalculator->at($assetOperations, $assetValuations, $before)->value;
                $row->endValue = $this->valuationCalculator->at($assetOperations, $assetValuations, $end)->value;
            } else {
                $row->startValue = $this->value($assetOperations->filter(fn (AssetOperation $op): bool => $op->date->lte($before)), $startPrices->get($asset->id)?->price);
                $row->endValue = $this->value($untilEnd, $endPrices->get($asset->id)?->price);
            }

            foreach ($assetOperations->filter(fn (AssetOperation $op): bool => ($op->type->isTrade() || $op->type->isCashFlow()) && $op->date->between($start, $end)) as $operation) {
                $cash = abs(ManageOperations::cashAmount($operation));
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
                    $row->realized += $sale->resultMinor();
                }
            }

            $row->incomes = (int) $incomes->get($asset->id, collect())->sum(fn (AssetIncome $income): int => $income->netAmount());

            if ($row->isEmpty()) {
                continue;
            }

            $rows[] = $row;

            if ($asset->currency === 'BRL') {
                $total->add($row);
            }
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
