<?php

namespace App\Filament\Monthly;

use Filament\Support\RawJs;

/**
 * Opções de Chart.js comuns: valores em R$ no eixo e no tooltip (trocados por "R$ •••" no modo privacidade), grade discreta.
 */
class ChartFormatting
{
    /**
     * Paleta validada (skill dataviz, modo claro): entradas = azul, saídas = laranja, magnitude única = azul.
     */
    public const BLUE = '#2a78d6';

    public const ORANGE = '#eb6834';

    public const INK = '#52514e';

    /**
     * Paleta categórica (skill dataviz), em ordem fixa: azul, laranja, água, amarelo, magenta, verde, violeta, vermelho.
     *
     * @var list<string>
     */
    public const CATEGORICAL = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];

    /** Fatia "Outras": cinza neutro, para não competir com as categorias. */
    public const OTHER = '#9a9893';

    /**
     * Donut: legenda à direita, tooltip com R$ e % do total, sem eixos.
     */
    public static function donutOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: { display: true, position: 'right', labels: { usePointStyle: true, boxWidth: 8 } },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                const total = context.dataset.data.reduce((sum, value) => sum + value, 0);
                                const percent = total ? new Intl.NumberFormat('pt-BR', { style: 'percent', maximumFractionDigits: 1 }).format(context.parsed / total) : '';
                                const money = window.fcPrivacy?.isOn() ? 'R$ •••' : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(context.parsed);

                                return ' ' + money + (percent ? ' (' + percent + ')' : '');
                            },
                        },
                    },
                },
                scales: { x: { display: false }, y: { display: false } },
            }
        JS);
    }

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
                                + (window.fcPrivacy?.isOn() ? 'R$ •••' : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(context.parsed.{$valueAxis})),
                        },
                    },
                },
                scales: {
                    {$valueAxis}: {
                        grid: { color: 'rgba(128, 128, 128, 0.15)' },
                        border: { display: false },
                        ticks: {
                            callback: (value) => window.fcPrivacy?.isOn() ? 'R$ •••' : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: 0 }).format(value),
                        },
                    },
                    {$categoryAxis}: { grid: { display: false } },
                },
            }
        JS);
    }
}
