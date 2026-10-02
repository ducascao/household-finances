<?php

namespace App\Filament\Widgets;

use App\Domain\Transactions\BillsSummary;
use App\Filament\Resources\Transactions\Actions\MarkAsPaidActions;
use App\Filament\Support\Sensitive;
use App\Models\Transaction;
use App\Support\MoneyFormatter;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class UpcomingBillsWidget extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Atrasados e próximos '.BillsSummary::DUE_SOON_DAYS.' dias')
            ->query(fn (): Builder => app(BillsSummary::class)->upcomingQuery()
                ->with(['account', 'category.parent'])
                ->orderBy('due_date')
                ->orderBy('id'))
            ->emptyStateHeading('Nada vencido ou vencendo nos próximos dias')
            ->paginated([10, 25])
            ->columns([
                TextColumn::make('due_date')
                    ->label('Vencimento')
                    ->date('d/m/Y')
                    ->badge()
                    ->color(fn (Transaction $record): string => $record->isOverdue() ? 'danger' : 'warning'),
                TextColumn::make('description')
                    ->label('Descrição'),
                TextColumn::make('category.name')
                    ->label('Categoria')
                    ->state(fn (Transaction $record): string => (string) $record->category?->fullName()),
                TextColumn::make('account.name')
                    ->label('Conta'),
                TextColumn::make('amount')
                    ->extraAttributes(Sensitive::ATTRIBUTES, merge: true)
                    ->label('Valor')
                    ->alignEnd()
                    ->color(fn (Transaction $record): string => $record->amount->isNegative() ? 'danger' : 'success')
                    ->formatStateUsing(fn (Transaction $record): string => MoneyFormatter::format($record->amount)),
            ])
            ->recordActions([
                MarkAsPaidActions::single(),
            ]);
    }
}
