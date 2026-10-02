<?php

namespace App\Filament\Widgets;

use App\Domain\Goals\GoalProgress;
use App\Filament\Resources\Goals\GoalResource;
use App\Filament\Support\Sensitive;
use App\Models\Goal;
use App\Support\MoneyFormatter;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Metas ativas no Painel de Controle: progresso, quanto guardar por mês e situação.
 */
class GoalsWidget extends TableWidget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Goal::query()->whereNull('archived_at')->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Metas')
            ->query(fn (): Builder => Goal::query()->whereNull('archived_at')->orderBy('deadline'))
            ->paginated(false)
            ->recordUrl(fn (Goal $record): string => GoalResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')->label('Meta')->weight('bold'),
                ViewColumn::make('progress')->label('Progresso')->view('filament.goals.progress'),
                TextColumn::make('current')->extraAttributes(Sensitive::ATTRIBUTES, merge: true)->label('Já tem / alvo')->alignEnd()
                    ->state(fn (Goal $record): string => MoneyFormatter::formatMinor((new GoalProgress($record))->current).' / '.MoneyFormatter::formatMinor($record->target)),
                TextColumn::make('pace')->extraAttributes(Sensitive::ATTRIBUTES, merge: true)->label('Guardar por mês')->alignEnd()
                    ->state(fn (Goal $record): string => ($pace = (new GoalProgress($record))->monthlyPace()) > 0 ? MoneyFormatter::formatMinor($pace).' até '.$record->deadline->format('m/Y') : '—'),
            ]);
    }
}
