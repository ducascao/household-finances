<?php

namespace App\Filament\Resources\Transactions\Tables;

use App\Domain\Transfers\TransferLabel;
use App\Enums\TransactionStatus;
use App\Filament\Resources\Transactions\Actions\MarkAsPaidActions;
use App\Filament\Resources\Transactions\Actions\TransferActions;
use App\Filament\Resources\Transactions\Schemas\TransactionForm;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Support\MoneyFormatter;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class TransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['account', 'category.parent', 'payer']))
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('date')->orderByDesc('id'))
            ->columns([
                TextColumn::make('date')
                    ->label('Data')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->searchable()
                    ->description(fn (Transaction $record): ?string => $record->tags !== [] ? implode(' · ', $record->tags) : null),
                TextColumn::make('category.name')
                    ->label('Categoria')
                    ->state(fn (Transaction $record): string => $record->isTransfer()
                        ? TransferLabel::for($record, self::viewer())
                        : (string) $record->category?->fullName())
                    ->color(fn (Transaction $record): ?string => $record->isTransfer() ? 'gray' : null),
                TextColumn::make('account.name')
                    ->label('Conta'),
                TextColumn::make('payer.name')
                    ->label('Pago por')
                    ->toggleable(),
                TextColumn::make('competence_date')
                    ->label('Competência')
                    ->date('m/Y')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->state(fn (Transaction $record): string => $record->isOverdue() ? 'Atrasado' : $record->status->label())
                    ->color(fn (Transaction $record): string => match (true) {
                        $record->isOverdue() => 'danger',
                        $record->isScheduled() => 'warning',
                        default => 'success',
                    }),
                TextColumn::make('due_date')
                    ->label('Vencimento')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('amount')
                    ->label('Valor')
                    ->alignEnd()
                    ->sortable()
                    ->color(fn (Transaction $record): string => $record->amount->isNegative() ? 'danger' : 'success')
                    ->formatStateUsing(fn (Transaction $record): string => MoneyFormatter::format($record->amount)),
            ])
            ->filters([
                SelectFilter::make('account_id')
                    ->label('Conta')
                    ->options(fn (): array => Account::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->multiple(),
                SelectFilter::make('category_id')
                    ->label('Categoria')
                    ->options(fn (): array => TransactionForm::categoryOptions())
                    // Filtrar pela categoria principal inclui as subcategorias.
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $categoryId) => $query->whereIn(
                            'category_id',
                            Category::query()->whereKey($categoryId)->orWhere('parent_id', $categoryId)->select('id'),
                        ),
                    )),
                Filter::make('period')
                    ->label('Período')
                    ->schema([
                        DatePicker::make('from')->label('De')->displayFormat('d/m/Y')->native(false),
                        DatePicker::make('until')->label('Até')->displayFormat('d/m/Y')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('date', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['from'] ?? null) {
                            $indicators[] = 'De '.Carbon::parse($data['from'])->format('d/m/Y');
                        }

                        if ($data['until'] ?? null) {
                            $indicators[] = 'Até '.Carbon::parse($data['until'])->format('d/m/Y');
                        }

                        return $indicators;
                    }),
                SelectFilter::make('kind')
                    ->label('Tipo')
                    ->options(['entries' => 'Receitas e despesas', 'transfers' => 'Transferências'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'entries' => $query->whereNull('transfer_id'),
                        'transfers' => $query->whereNotNull('transfer_id'),
                        default => $query,
                    }),
                SelectFilter::make('status')
                    ->label('Situação')
                    ->options(TransactionStatus::options()),
                Filter::make('overdue')
                    ->label('Só atrasados')
                    ->toggle()
                    ->query(function (Builder $query): void {
                        /** @var Builder<Transaction> $query */
                        $query->overdue();
                    }),
                SelectFilter::make('paid_by')
                    ->label('Pago por')
                    ->options(fn (): array => TransactionForm::memberOptions()),
            ])
            ->recordActions([
                MarkAsPaidActions::single(),
                EditAction::make()
                    ->hidden(fn (Transaction $record): bool => $record->isTransfer()),
                TransferActions::edit(),
                TransferActions::delete(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    MarkAsPaidActions::bulk(),
                    TransferActions::bulkDelete(),
                ]),
            ]);
    }

    private static function viewer(): User
    {
        /** @var User */
        return auth()->user();
    }
}
