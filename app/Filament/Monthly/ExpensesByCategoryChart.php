<?php

namespace App\Filament\Monthly;

use App\Domain\Reports\MonthlySummary;
use App\Models\Category;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Gastos por categoria principal num gráfico de donut, do maior para o menor.
 * Acima de 8 categorias, o restante é somado em "Outras".
 */
class ExpensesByCategoryChart extends ChartWidget
{
    use InteractsWithPageFilters, SelectedMonth;

    protected static ?int $sort = 2;

    protected ?string $heading = 'Gastos por categoria';

    protected ?string $maxHeight = '320px';

    public const MAX_SLICES = 8;

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        return self::donut(app(MonthlySummary::class)->expensesByCategory($this->month()));
    }

    /**
     * Dados do donut: uma cor da paleta categórica por fatia, na ordem; "Outras" em cinza.
     *
     * @param  list<array{category: Category, total: int}>  $rows
     * @return array<string, mixed>
     */
    public static function donut(array $rows): array
    {
        ['labels' => $labels, 'values' => $values, 'folded' => $folded] = self::fold($rows);

        $colors = array_slice(ChartFormatting::CATEGORICAL, 0, count($values));

        if ($folded) {
            $colors[count($colors) - 1] = ChartFormatting::OTHER;
        }

        return [
            'labels' => $labels,
            'datasets' => [[
                'label' => 'Gasto',
                'data' => $values,
                'backgroundColor' => $colors,
                'borderWidth' => 0,
                'spacing' => 2,
                'hoverOffset' => 6,
            ]],
        ];
    }

    /**
     * Até 8 fatias: acima disso, as menores viram "Outras".
     *
     * @param  list<array{category: Category, total: int}>  $rows
     * @return array{labels: list<string>, values: list<float>, folded: bool}
     */
    private static function fold(array $rows): array
    {
        $shown = array_slice($rows, 0, self::MAX_SLICES - (count($rows) > self::MAX_SLICES ? 1 : 0));
        $rest = array_slice($rows, count($shown));

        $labels = array_map(fn (array $row): string => $row['category']->name, $shown);
        $values = array_map(fn (array $row): float => $row['total'] / 100, $shown);

        if ($rest !== []) {
            $labels[] = 'Outras';
            $values[] = array_sum(array_column($rest, 'total')) / 100;
        }

        return ['labels' => $labels, 'values' => $values, 'folded' => $rest !== []];
    }

    protected function getOptions(): RawJs
    {
        return ChartFormatting::donutOptions();
    }
}
