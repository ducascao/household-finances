<?php

namespace App\Filament\Monthly;

use App\Domain\Reports\MonthlySummary;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Gastos por categoria principal: barras horizontais de uma cor só, do maior para o menor.
 * Acima de 8 categorias, o restante é somado em "Outras".
 */
class ExpensesByCategoryChart extends ChartWidget
{
    use InteractsWithPageFilters, SelectedMonth;

    protected static ?int $sort = 2;

    protected ?string $heading = 'Gastos por categoria';

    protected ?string $maxHeight = '320px';

    public const MAX_BARS = 8;

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $rows = app(MonthlySummary::class)->expensesByCategory($this->month());
        $shown = array_slice($rows, 0, self::MAX_BARS - (count($rows) > self::MAX_BARS ? 1 : 0));
        $rest = array_slice($rows, count($shown));

        $labels = array_map(fn (array $row): string => $row['category']->name, $shown);
        $values = array_map(fn (array $row): float => $row['total'] / 100, $shown);

        if ($rest !== []) {
            $labels[] = 'Outras';
            $values[] = array_sum(array_column($rest, 'total')) / 100;
        }

        return [
            'labels' => $labels,
            'datasets' => [[
                'label' => 'Gasto',
                'data' => $values,
                'backgroundColor' => ChartFormatting::BLUE,
                'borderRadius' => 4,
                'borderSkipped' => 'start',
                'barPercentage' => 0.7,
            ]],
        ];
    }

    protected function getOptions(): RawJs
    {
        return ChartFormatting::options(valueAxis: 'x', legend: false);
    }
}
