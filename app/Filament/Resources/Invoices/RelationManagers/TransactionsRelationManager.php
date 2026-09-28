<?php

namespace App\Filament\Resources\Invoices\RelationManagers;

use App\Models\Transaction;
use App\Support\MoneyFormatter;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Itens da fatura (somente leitura; edição pela tela de lançamentos).
 */
class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    protected static ?string $title = 'Itens da fatura';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['category.parent', 'payer']))
            ->defaultSort('date')
            ->paginated(false)
            ->columns([
                TextColumn::make('date')
                    ->label('Data')
                    ->date('d/m/Y'),
                TextColumn::make('description')
                    ->label('Descrição'),
                TextColumn::make('category.name')
                    ->label('Categoria')
                    ->state(fn (Transaction $record): string => (string) $record->category?->fullName()),
                TextColumn::make('payer.name')
                    ->label('Pago por'),
                TextColumn::make('amount')
                    ->label('Valor')
                    ->alignEnd()
                    ->color(fn (Transaction $record): string => $record->amount->isNegative() ? 'danger' : 'success')
                    ->formatStateUsing(fn (Transaction $record): string => MoneyFormatter::format($record->amount)),
            ]);
    }
}
