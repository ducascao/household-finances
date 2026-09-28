<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Domain\CreditCard\InvoiceTotals;
use App\Models\CreditCard;
use App\Models\Invoice;
use App\Support\MoneyFormatter;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make(fn (Invoice $record): string => 'Fatura de '.$record->label())
                    ->columns(3)
                    ->columnSpan(2)
                    ->schema([
                        TextEntry::make('card')
                            ->label('Cartão')
                            ->state(fn (Invoice $record): string => self::card($record)->account->name),
                        TextEntry::make('closing_date')
                            ->label('Fechamento')
                            ->date('d/m/Y'),
                        TextEntry::make('due_date')
                            ->label('Vencimento')
                            ->date('d/m/Y'),
                        TextEntry::make('amount_due')
                            ->label('Total')
                            ->size('lg')
                            ->weight('bold')
                            ->state(fn (Invoice $record): string => MoneyFormatter::format(InvoiceTotals::amountDue($record))),
                        TextEntry::make('status')
                            ->label('Situação')
                            ->badge()
                            ->state(fn (Invoice $record): string => $record->isOverdue() ? 'Atrasada' : $record->status()->label())
                            ->color(fn (Invoice $record): string => $record->isOverdue() ? 'danger' : $record->status()->color()),
                        TextEntry::make('paid_at')
                            ->label('Paga em')
                            ->date('d/m/Y')
                            ->placeholder('—'),
                    ]),
                Section::make('Limite')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('limit')
                            ->label('Limite total')
                            ->state(fn (Invoice $record): string => MoneyFormatter::format(self::card($record)->limit)),
                        TextEntry::make('available_limit')
                            ->label('Disponível')
                            ->helperText('Limite menos compras e parcelas em faturas ainda não pagas.')
                            ->weight('bold')
                            ->state(fn (Invoice $record): string => MoneyFormatter::format(InvoiceTotals::availableLimit(self::card($record)))),
                    ]),
                Section::make('Próximas faturas')
                    ->description('Compras e parcelas já comprometidas.')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('upcoming')
                            ->hiddenLabel()
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder('Nenhuma compra em faturas futuras.')
                            ->state(fn (Invoice $record): array => self::upcoming($record)),
                    ]),
            ]);
    }

    private static function card(Invoice $invoice): CreditCard
    {
        return CreditCard::withoutGlobalScopes()->with('account')->findOrFail($invoice->credit_card_id);
    }

    /**
     * @return list<string>
     */
    private static function upcoming(Invoice $invoice): array
    {
        return InvoiceTotals::addToQuery(Invoice::withoutGlobalScopes())
            ->where('credit_card_id', $invoice->credit_card_id)
            ->whereDate('reference_month', '>', $invoice->reference_month)
            ->orderBy('reference_month')
            ->get()
            ->filter(fn (Invoice $next): bool => ! InvoiceTotals::amountDue($next)->isZero())
            ->map(fn (Invoice $next): string => $next->label().' (vence '.$next->due_date->format('d/m/Y').'): '
                .MoneyFormatter::format(InvoiceTotals::amountDue($next)))
            ->values()
            ->all();
    }
}
