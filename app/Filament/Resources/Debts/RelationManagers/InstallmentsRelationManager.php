<?php

namespace App\Filament\Resources\Debts\RelationManagers;

use App\Domain\Debts\DebtSummary;
use App\Models\Debt;
use App\Models\DebtInstallment;
use App\Support\MoneyFormatter;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Cronograma completo. Pagar uma parcela é marcar o lançamento dela como pago (Lançamentos ou Painel).
 */
class InstallmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'installments';

    protected static ?string $title = 'Parcelas';

    private ?DebtSummary $summary = null;

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('transaction'))
            ->defaultSort('number')
            ->paginated([12, 24, 60, 120])
            ->defaultPaginationPageOption(12)
            ->columns([
                TextColumn::make('number')->label('Nº'),
                TextColumn::make('due_date')->label('Vencimento')->date('d/m/Y'),
                TextColumn::make('amortization')->label('Amortização')->alignEnd()->formatStateUsing(fn (int $state): string => MoneyFormatter::formatMinor($state)),
                TextColumn::make('interest')->label('Juros')->alignEnd()->formatStateUsing(fn (int $state): string => MoneyFormatter::formatMinor($state)),
                TextColumn::make('total')->label('Parcela')->alignEnd()->weight('bold')->formatStateUsing(fn (int $state): string => MoneyFormatter::formatMinor($state)),
                TextColumn::make('balance_after')->label('Saldo depois')->alignEnd()->formatStateUsing(fn (int $state): string => MoneyFormatter::formatMinor($state)),
                TextColumn::make('status')->label('Situação')->badge()
                    ->state(fn (DebtInstallment $record): string => match (true) {
                        $this->summary()->isPaid($record) => 'Paga',
                        $this->summary()->isOverdue($record) => 'Atrasada',
                        $record->transaction_id !== null => 'Prevista',
                        default => 'Futura',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Paga' => 'success',
                        'Atrasada' => 'danger',
                        'Prevista' => 'warning',
                        default => 'gray',
                    }),
            ]);
    }

    private function summary(): DebtSummary
    {
        /** @var Debt $debt */
        $debt = $this->getOwnerRecord();

        return $this->summary ??= new DebtSummary($debt);
    }
}
