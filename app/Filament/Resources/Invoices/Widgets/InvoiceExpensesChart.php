<?php

namespace App\Filament\Resources\Invoices\Widgets;

use App\Domain\CreditCard\InvoiceCategoryBreakdown;
use App\Filament\Monthly\ChartFormatting;
use App\Filament\Monthly\ExpensesByCategoryChart;
use App\Models\Invoice;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/**
 * Gastos da fatura por categoria principal, no mesmo formato do resumo do mês.
 */
class InvoiceExpensesChart extends ChartWidget
{
    public ?Invoice $record = null;

    protected ?string $heading = 'Gastos por categoria';

    protected ?string $maxHeight = '320px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $rows = $this->record === null ? [] : InvoiceCategoryBreakdown::of($this->record)['categories'];

        return ExpensesByCategoryChart::bars($rows);
    }

    protected function getOptions(): RawJs
    {
        return ChartFormatting::options(valueAxis: 'x', legend: false);
    }
}
