<?php

namespace App\Filament\Resources\Recurrences\Tables;

use App\Domain\Recurrences\DeleteRecurrence;
use App\Domain\Recurrences\RecurrenceSchedule;
use App\Enums\RecurrenceFrequency;
use App\Models\Recurrence;
use App\Models\User;
use App\Support\MoneyFormatter;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class RecurrencesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['account', 'category.parent']))
            ->defaultSort('next_date')
            ->columns([
                TextColumn::make('description')
                    ->label('Descrição')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category.name')
                    ->label('Categoria')
                    ->state(fn (Recurrence $record): string => $record->category->fullName()),
                TextColumn::make('account.name')
                    ->label('Conta'),
                TextColumn::make('amount')
                    ->label('Valor')
                    ->alignEnd()
                    ->color(fn (Recurrence $record): string => $record->amount->isNegative() ? 'danger' : 'success')
                    ->formatStateUsing(fn (Recurrence $record): string => ($record->amount_is_estimate ? '~ ' : '').MoneyFormatter::format($record->amount))
                    ->description(fn (Recurrence $record): ?string => $record->amount_is_estimate ? 'estimado' : null),
                TextColumn::make('frequency')
                    ->label('Repetição')
                    ->state(fn (Recurrence $record): string => RecurrenceSchedule::describe($record)),
                TextColumn::make('next_date')
                    ->label('Próxima a gerar')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('end_date')
                    ->label('Fim')
                    ->date('d/m/Y')
                    ->placeholder('sem fim'),
            ])
            ->filters([
                SelectFilter::make('frequency')
                    ->label('Frequência')
                    ->options(RecurrenceFrequency::options()),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription('Os lançamentos já gerados continuam existindo, sem vínculo com a conta fixa.')
                    ->schema([
                        Toggle::make('delete_future')
                            ->label('Excluir também os previstos de hoje em diante ainda não pagos')
                            ->default(true),
                    ])
                    ->using(function (Recurrence $record, array $data): bool {
                        /** @var User $user */
                        $user = auth()->user();

                        try {
                            app(DeleteRecurrence::class)->execute($user, $record, (bool) ($data['delete_future'] ?? false));
                        } catch (ValidationException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return false;
                        }

                        return true;
                    }),
            ]);
    }
}
