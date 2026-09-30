<?php

namespace App\Domain\Investments;

use App\Domain\Currency\ExchangeRates;
use App\Domain\Currency\ForeignCostCalculator;
use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\AssetOperation;
use App\Models\ManualValuation;
use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Illuminate\Support\Collection;

/**
 * Carteira do usuário logado (escopos globais): posições abertas, totais e distribuição por tipo.
 * Valores e totais em reais; ativos em outra moeda são convertidos pelo câmbio.
 */
class Portfolio
{
    public function __construct(
        private readonly PositionCalculator $calculator,
        private readonly PriceBook $prices,
        private readonly ValuationCalculator $valuations,
        private readonly ForeignCostCalculator $foreignCost,
        private readonly ExchangeRates $rates,
    ) {}

    /**
     * @return list<PortfolioRow>
     */
    public function rows(bool $onlyOpen = true): array
    {
        $assets = Asset::query()->with('account')->orderBy('type')->orderBy('name')->get();
        $operations = AssetOperation::query()->whereIn('asset_id', $assets->modelKeys())->get()->groupBy('asset_id');
        $prices = $this->prices->latestFor($assets->modelKeys());
        $valuations = ManualValuation::query()->whereIn('asset_id', $assets->modelKeys())->get()->groupBy('asset_id');

        $rows = [];

        foreach ($assets as $asset) {
            if ($asset->type->isValuedByBalance()) {
                $valuation = $this->valuations->at($operations->get($asset->id, collect()), $valuations->get($asset->id, collect()));

                if (! $onlyOpen || $valuation->value > 0) {
                    $rows[] = new PortfolioRow($asset, Position::empty(), null, $valuation, ...$this->brl($asset, $operations->get($asset->id, collect())));
                }

                continue;
            }

            $position = $this->calculator->calculate($operations->get($asset->id, collect()));

            if ($onlyOpen && ! $position->isOpen()) {
                continue;
            }

            $rows[] = new PortfolioRow($asset, $position, $prices->get($asset->id), null, ...$this->brl($asset, $operations->get($asset->id, collect())));
        }

        usort($rows, fn (PortfolioRow $a, PortfolioRow $b): int => ($b->marketValueBrl() ?? Money::zero('BRL'))->compareTo($a->marketValueBrl() ?? Money::zero('BRL')));

        return $rows;
    }

    /**
     * Custo em reais (câmbio de cada operação) e câmbio atual, para ativos em moeda estrangeira.
     *
     * @param  Collection<int, AssetOperation>  $operations
     * @return array{costBrlMinor: int|null, rateNow: BigDecimal|null}
     */
    private function brl(Asset $asset, Collection $operations): array
    {
        if ($asset->currency === 'BRL') {
            return ['costBrlMinor' => null, 'rateNow' => BigDecimal::one()];
        }

        return [
            'costBrlMinor' => $this->foreignCost->costBrl($operations, $asset->currency),
            'rateNow' => $this->rates->rateAt($asset->currency, today()),
        ];
    }

    /**
     * Totais em reais (exterior convertido pelo câmbio atual; sem câmbio, fica de fora e aparece em "missing").
     *
     * @param  list<PortfolioRow>  $rows
     * @return array{cost: Money, market: Money, result: Money, percent: float|null, missing: list<string>}
     */
    public function totals(array $rows): array
    {
        $cost = Money::zero('BRL');
        $market = Money::zero('BRL');
        $missing = [];

        foreach ($rows as $row) {
            $rowCost = $row->costBrl();
            $rowMarket = $row->marketValueBrl();

            if ($rowCost === null || $rowMarket === null) {
                $missing[] = $row->asset->label();

                continue;
            }

            $cost = $cost->plus($rowCost);
            $market = $market->plus($rowMarket);
        }

        $result = $market->minus($cost);

        return [
            'cost' => $cost,
            'market' => $market,
            'result' => $result,
            'percent' => $cost->isPositive() ? round((float) (string) $result->getAmount() / (float) (string) $cost->getAmount() * 100, 2) : null,
            'missing' => $missing,
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
            $value = $row->marketValueBrl();

            if ($value === null) {
                continue;
            }

            $key = $row->asset->type->value;
            $byType[$key] = isset($byType[$key]) ? $byType[$key]->plus($value) : $value;
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
