<?php

namespace App\Filament\Resources\Debts\Tables;

use App\Domain\Debts\DebtSummary;
use App\Enums\DebtStatus;
use App\Models\Debt;
use App\Support\MoneyFormatter;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DebtsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Dívida')->weight('bold')->searchable()
                    ->description(fn (Debt $record): string => $record->creditor.' · '.$record->system->label()),
                TextColumn::make('outstanding')->label('Saldo devedor')->alignEnd()
                    ->state(fn (Debt $record): string => MoneyFormatter::formatMinor((new DebtSummary($record))->outstanding())),
                TextColumn::make('progress')->label('Parcelas')
                    ->state(fn (Debt $record): string => (new DebtSummary($record))->paidCount().' / '.$record->installments_count),
                TextColumn::make('next')->label('Próxima parcela')
                    ->state(fn (Debt $record): string => ($next = (new DebtSummary($record))->next()) !== null
                        ? MoneyFormatter::formatMinor($next->total).' em '.$next->due_date->format('d/m/Y')
                        : '—'),
                TextColumn::make('payoff')->label('Quitação prevista')
                    ->state(fn (Debt $record): string => (new DebtSummary($record))->payoffDate()?->format('d/m/Y') ?? '—'),
                TextColumn::make('status')->label('Situação')->badge()
                    ->formatStateUsing(fn (DebtStatus $state): string => $state->label())
                    ->color(fn (DebtStatus $state): string => $state === DebtStatus::PaidOff ? 'success' : 'info'),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
