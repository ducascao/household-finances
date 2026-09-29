<?php

namespace App\Filament\Portfolio;

use App\Domain\Investments\Portfolio;
use App\Support\MoneyFormatter;
use Filament\Widgets\Widget;

/**
 * Distribuição por tipo: barras horizontais de uma cor com o % do valor de mercado (parte do todo, poucas classes).
 */
class PortfolioDistributionWidget extends Widget
{
    protected string $view = 'filament.portfolio.distribution';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return list<array{label: string, value: string, percent: float}>
     */
    public function distribution(): array
    {
        $portfolio = app(Portfolio::class);

        return array_map(fn (array $item): array => [
            'label' => $item['type']->label(),
            'value' => MoneyFormatter::format($item['value']),
            'percent' => $item['percent'],
        ], $portfolio->distribution($portfolio->rows()));
    }
}
