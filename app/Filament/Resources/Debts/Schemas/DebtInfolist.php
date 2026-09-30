<?php

namespace App\Filament\Resources\Debts\Schemas;

use App\Domain\Debts\DebtSummary;
use App\Domain\Investments\Quantity;
use App\Models\Debt;
use App\Support\MoneyFormatter;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DebtInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $summary = fn (Debt $debt): DebtSummary => new DebtSummary($debt);

        return $schema->components([
            Section::make(fn (Debt $record): string => $record->name)
                ->description(fn (Debt $record): string => $record->creditor.' · '.$record->system->label().' · '.Quantity::format($record->monthly_rate, 2).'% a.m.')
                ->columns(4)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('outstanding')->label('Saldo devedor')->weight('bold')->size('lg')
                        ->state(fn (Debt $record): string => MoneyFormatter::formatMinor($summary($record)->outstanding())),
                    TextEntry::make('progress')->label('Parcelas pagas')
                        ->state(fn (Debt $record): string => $summary($record)->paidCount().' de '.$record->installments_count),
                    TextEntry::make('next')->label('Próxima parcela')
                        ->state(fn (Debt $record): string => ($next = $summary($record)->next()) !== null
                            ? MoneyFormatter::formatMinor($next->total).' em '.$next->due_date->format('d/m/Y')
                            : 'quitada'),
                    TextEntry::make('payoff')->label('Quitação prevista')
                        ->state(fn (Debt $record): string => $summary($record)->payoffDate()?->format('d/m/Y') ?? 'quitada'),
                    TextEntry::make('principal')->label('Valor financiado')
                        ->state(fn (Debt $record): string => MoneyFormatter::formatMinor($record->principal)),
                    TextEntry::make('interest_paid')->label('Juros pagos')
                        ->state(fn (Debt $record): string => MoneyFormatter::formatMinor($summary($record)->interestPaid())),
                    TextEntry::make('interest_remaining')->label('Juros a pagar')
                        ->state(fn (Debt $record): string => MoneyFormatter::formatMinor($summary($record)->interestRemaining())),
                    TextEntry::make('total_remaining')->label('Total a pagar')
                        ->state(fn (Debt $record): string => MoneyFormatter::formatMinor($summary($record)->totalRemaining())),
                    TextEntry::make('prepaid')->label('Amortizações extraordinárias')
                        ->state(fn (Debt $record): string => MoneyFormatter::formatMinor($summary($record)->prepaid())),
                    TextEntry::make('paymentAccount.name')->label('Conta de pagamento'),
                    TextEntry::make('category.name')->label('Categoria'),
                ]),
        ]);
    }
}
