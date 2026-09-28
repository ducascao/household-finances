<?php

namespace App\Filament\Monthly;

use App\Domain\Reports\MonthlySummary;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Entradas × saídas dos 12 meses até o mês escolhido, com o resultado como linha no mesmo eixo (mesma unidade).
 */
class EvolutionChart extends ChartWidget
{
    use InteractsWithPageFilters, SelectedMonth;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Últimos 12 meses';

    protected ?string $maxHeight = '300px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $series = app(MonthlySummary::class)->evolution($this->month());

        return [
            'labels' => array_map(fn (array $point): string => $point['month']->locale('pt_BR')->translatedFormat('M/y'), $series),
            'datasets' => [
                [
                    'label' => 'Entradas',
                    'data' => array_map(fn (array $point): float => $point['income'] / 100, $series),
                    'backgroundColor' => ChartFormatting::BLUE,
                    'borderRadius' => 4,
                    'borderSkipped' => 'start',
                    'order' => 2,
                ],
                [
                    'label' => 'Saídas',
                    'data' => array_map(fn (array $point): float => $point['expense'] / 100, $series),
                    'backgroundColor' => ChartFormatting::ORANGE,
                    'borderRadius' => 4,
                    'borderSkipped' => 'start',
                    'order' => 2,
                ],
                [
                    'type' => 'line',
                    'label' => 'Resultado',
                    'data' => array_map(fn (array $point): float => $point['result'] / 100, $series),
                    'borderColor' => ChartFormatting::INK,
                    'backgroundColor' => ChartFormatting::INK,
                    'borderWidth' => 2,
                    'pointRadius' => 4,
                    'tension' => 0,
                    'order' => 1,
                ],
            ],
        ];
    }

    protected function getOptions(): RawJs
    {
        return ChartFormatting::options(valueAxis: 'y');
    }
}
