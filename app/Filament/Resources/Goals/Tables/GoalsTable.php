<?php

namespace App\Filament\Resources\Goals\Tables;

use App\Domain\Goals\GoalProgress;
use App\Domain\Goals\SaveGoal;
use App\Models\Goal;
use App\Models\User;
use App\Support\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class GoalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('deadline')
            ->columns([
                TextColumn::make('name')->label('Meta')->weight('bold')
                    ->description(fn (Goal $record): string => $record->visibility->label()),
                ViewColumn::make('progress')->label('Progresso')->view('filament.goals.progress'),
                TextColumn::make('current')->label('Já tem / alvo')->alignEnd()
                    ->state(fn (Goal $record): string => MoneyFormatter::formatMinor((new GoalProgress($record))->current))
                    ->description(fn (Goal $record): string => 'de '.MoneyFormatter::formatMinor($record->target)),
                TextColumn::make('deadline')->label('Prazo')->date('d/m/Y'),
                TextColumn::make('pace')->label('Guardar por mês')->alignEnd()
                    ->state(fn (Goal $record): string => ($pace = (new GoalProgress($record))->monthlyPace()) > 0 ? MoneyFormatter::formatMinor($pace) : '—'),
            ])
            ->filters([
                TernaryFilter::make('archived')
                    ->label('Arquivadas')
                    ->placeholder('Só ativas')
                    ->trueLabel('Só arquivadas')
                    ->falseLabel('Todas')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('archived_at'),
                        false: fn (Builder $query) => $query,
                        blank: fn (Builder $query) => $query->whereNull('archived_at'),
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('archive')
                    ->label(fn (Goal $record): string => $record->archived_at !== null ? 'Reativar' : 'Arquivar')
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->action(function (Goal $record): void {
                        /** @var User $user */
                        $user = auth()->user();
                        app(SaveGoal::class)->archive($user, $record, $record->archived_at === null);
                    }),
            ]);
    }
}
