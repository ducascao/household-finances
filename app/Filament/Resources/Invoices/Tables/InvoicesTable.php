<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Domain\CreditCard\InvoiceTotals;
use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\Actions\PayInvoiceAction;
use App\Models\CreditCard;
use App\Models\Invoice;
use App\Support\MoneyFormatter;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => InvoiceTotals::addToQuery($query->with('creditCard.account')))
            ->defaultSort('due_date', 'desc')
            ->recordUrl(fn (Invoice $record): string => route('filament.app.resources.invoices.view', $record))
            ->columns([
                TextColumn::make('creditCard.account.name')
                    ->label('Cartão'),
                TextColumn::make('reference_month')
                    ->label('Fatura')
                    ->formatStateUsing(fn (Invoice $record): string => $record->label())
                    ->sortable(),
                TextColumn::make('closing_date')
                    ->label('Fechamento')
                    ->date('d/m/Y'),
                TextColumn::make('due_date')
                    ->label('Vencimento')
                    ->date('d/m/Y')
                    ->sortable()
                    ->color(fn (Invoice $record): ?string => $record->isOverdue() ? 'danger' : null),
                TextColumn::make('amount_due')
                    ->label('Total')
                    ->alignEnd()
                    ->formatStateUsing(fn (Invoice $record): string => MoneyFormatter::format(InvoiceTotals::amountDue($record))),
                TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->state(fn (Invoice $record): string => $record->isOverdue() ? 'Atrasada' : $record->status()->label())
                    ->color(fn (Invoice $record): string => $record->isOverdue() ? 'danger' : $record->status()->color()),
            ])
            ->filters([
                SelectFilter::make('credit_card_id')
                    ->label('Cartão')
                    ->options(fn (): array => CreditCard::query()->with('account')->get()
                        ->mapWithKeys(fn (CreditCard $card): array => [$card->id => $card->account->name])
                        ->all()),
                SelectFilter::make('paid')
                    ->label('Situação')
                    ->options(['unpaid' => 'Não pagas', 'paid' => InvoiceStatus::Paid->label()])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'unpaid' => $query->whereNull('paid_at'),
                        'paid' => $query->whereNotNull('paid_at'),
                        default => $query,
                    }),
            ])
            ->recordActions([
                PayInvoiceAction::make(),
                ViewAction::make(),
            ]);
    }
}
