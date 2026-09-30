<?php

namespace App\Filament\NetWorth;

use App\Filament\Monthly\ChartFormatting;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Evolução do patrimônio líquido mês a mês (uma série: linha de uma cor, sem legenda).
 */
class NetWorthChart extends ChartWidget
{
    use InteractsWithPageFilters, SelectedView;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Evolução do patrimônio líquido';

    protected ?string $maxHeight = '300px';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $series = $this->history()->series($this->viewer(), $this->isHousehold());

        return [
            'labels' => array_map(fn (array $point): string => $point['month']->locale('pt_BR')->translatedFormat('M/y').($point['live'] ? ' (hoje)' : ''), $series),
            'datasets' => [[
                'label' => 'Patrimônio líquido',
                'data' => array_map(fn (array $point): float => $point['net_worth'] / 100, $series),
                'borderColor' => ChartFormatting::BLUE,
                'backgroundColor' => ChartFormatting::BLUE,
                'borderWidth' => 2,
                'pointRadius' => 4,
                'tension' => 0,
            ]],
        ];
    }

    protected function getOptions(): RawJs
    {
        return ChartFormatting::options(valueAxis: 'y', legend: false);
    }
}
