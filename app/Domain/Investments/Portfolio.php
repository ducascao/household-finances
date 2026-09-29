<?php

namespace App\Domain\Investments;

use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\AssetOperation;
use Brick\Money\Money;

/**
 * Carteira do usuário logado (escopos globais): posições abertas, totais e distribuição por tipo.
 * Totais em BRL (ativos em outra moeda chegam na E12).
 */
class Portfolio
{
    public function __construct(
        private readonly PositionCalculator $calculator,
        private readonly PriceBook $prices,
    ) {}

    /**
     * @return list<PortfolioRow>
     */
    public function rows(bool $onlyOpen = true): array
    {
        $assets = Asset::query()->with('account')->orderBy('ticker')->get();
        $operations = AssetOperation::query()->whereIn('asset_id', $assets->modelKeys())->get()->groupBy('asset_id');
        $prices = $this->prices->latestFor($assets->modelKeys());

        $rows = [];

        foreach ($assets as $asset) {
            $position = $this->calculator->calculate($operations->get($asset->id, collect()));

            if ($onlyOpen && ! $position->isOpen()) {
                continue;
            }

            $rows[] = new PortfolioRow($asset, $position, $prices->get($asset->id));
        }

        usort($rows, fn (PortfolioRow $a, PortfolioRow $b): int => $b->marketValue()->compareTo($a->marketValue()));

        return $rows;
    }

    /**
     * @param  list<PortfolioRow>  $rows
     * @return array{cost: Money, market: Money, result: Money, percent: float|null}
     */
    public function totals(array $rows): array
    {
        $cost = Money::zero('BRL');
        $market = Money::zero('BRL');

        foreach ($rows as $row) {
            if ($row->asset->currency === 'BRL') {
                $cost = $cost->plus($row->cost());
                $market = $market->plus($row->marketValue());
            }
        }

        $result = $market->minus($cost);

        return [
            'cost' => $cost,
            'market' => $market,
            'result' => $result,
            'percent' => $cost->isPositive() ? round((float) (string) $result->getAmount() / (float) (string) $cost->getAmount() * 100, 2) : null,
        ];
    }

    /**
     * Valor de mercado por tipo, com % do total, do maior para o menor.
     *
     * @param  list<PortfolioRow>  $rows
     * @return list<array{type: AssetType, value: Money, percent: float}>
     */
    public function distribution(array $rows): array
    {
        $byType = [];

        foreach ($rows as $row) {
            if ($row->asset->currency !== 'BRL') {
                continue;
            }

            $key = $row->asset->type->value;
            $byType[$key] = isset($byType[$key]) ? $byType[$key]->plus($row->marketValue()) : $row->marketValue();
        }

        $total = array_reduce($byType, fn (Money $carry, Money $value): Money => $carry->plus($value), Money::zero('BRL'));
        $distribution = [];

        foreach ($byType as $type => $value) {
            $distribution[] = [
                'type' => AssetType::from($type),
                'value' => $value,
                'percent' => $total->isPositive() ? round((float) (string) $value->getAmount() / (float) (string) $total->getAmount() * 100, 1) : 0.0,
            ];
        }

        usort($distribution, fn (array $a, array $b): int => $b['value']->compareTo($a['value']));

        return $distribution;
    }
}
