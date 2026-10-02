<?php

namespace App\Filament\Widgets;

use App\Domain\CreditCard\InvoiceTotals;
use App\Domain\Transactions\BillsSummary;
use App\Filament\Resources\Invoices\Actions\PayInvoiceAction;
use App\Filament\Support\Sensitive;
use App\Models\Invoice;
use App\Support\MoneyFormatter;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class UpcomingInvoicesWidget extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return app(BillsSummary::class)->upcomingInvoices()->isNotEmpty();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Faturas de cartão atrasadas e dos próximos '.BillsSummary::DUE_SOON_DAYS.' dias')
            ->query(fn (): Builder => InvoiceTotals::addToQuery(Invoice::query())
                ->with('creditCard.account')
                ->whereKey(app(BillsSummary::class)->upcomingInvoices()->pluck('id')->all())
                ->orderBy('due_date'))
            ->paginated(false)
            ->recordUrl(fn (Invoice $record): string => route('filament.app.resources.invoices.view', $record))
            ->columns([
                TextColumn::make('due_date')
                    ->label('Vencimento')
                    ->date('d/m/Y')
                    ->badge()
                    ->color(fn (Invoice $record): string => $record->isOverdue() ? 'danger' : 'warning'),
                TextColumn::make('creditCard.account.name')
                    ->label('Cartão'),
                TextColumn::make('reference_month')
                    ->label('Fatura')
                    ->formatStateUsing(fn (Invoice $record): string => $record->label()),
                TextColumn::make('amount_due')
                    ->extraAttributes(Sensitive::ATTRIBUTES, merge: true)
                    ->label('Total')
                    ->alignEnd()
                    ->color('danger')
                    ->formatStateUsing(fn (Invoice $record): string => MoneyFormatter::format(InvoiceTotals::amountDue($record))),
            ])
            ->recordActions([
                PayInvoiceAction::make(),
            ]);
    }
}
