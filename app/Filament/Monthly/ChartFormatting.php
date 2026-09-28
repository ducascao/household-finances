<?php

namespace App\Filament\Monthly;

use Filament\Support\RawJs;

/**
 * Opções de Chart.js comuns: valores em R$ no eixo e no tooltip, grade discreta.
 */
class ChartFormatting
{
    /**
     * Paleta validada (skill dataviz, modo claro): entradas = azul, saídas = laranja, magnitude única = azul.
     */
    public const BLUE = '#2a78d6';

    public const ORANGE = '#eb6834';

    public const INK = '#52514e';

    public static function options(string $valueAxis = 'y', bool $legend = true): RawJs
    {
        $categoryAxis = $valueAxis === 'y' ? 'x' : 'y';
        $legendJs = $legend ? "{ display: true, position: 'top', labels: { usePointStyle: true, boxWidth: 8 } }" : '{ display: false }';

        return RawJs::make(<<<JS
            {
                indexAxis: '{$categoryAxis}',
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {$legendJs},
                    tooltip: {
                        callbacks: {
                            label: (context) => (context.dataset.label ? context.dataset.label + ': ' : '')
                                + new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(context.parsed.{$valueAxis}),
                        },
                    },
                },
                scales: {
                    {$valueAxis}: {
                        grid: { color: 'rgba(128, 128, 128, 0.15)' },
                        border: { display: false },
                        ticks: {
                            callback: (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: 0 }).format(value),
                        },
                    },
                    {$categoryAxis}: { grid: { display: false } },
                },
            }
        JS);
    }
}
