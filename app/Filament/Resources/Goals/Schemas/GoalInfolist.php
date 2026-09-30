<?php

namespace App\Filament\Resources\Goals\Schemas;

use App\Domain\Goals\GoalProgress;
use App\Models\Goal;
use App\Support\MoneyFormatter;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class GoalInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $progress = fn (Goal $goal): GoalProgress => new GoalProgress($goal);

        return $schema->components([
            Section::make(fn (Goal $record): string => $record->name)
                ->description(fn (Goal $record): string => $record->visibility->label().' · de '.$record->start_date->format('d/m/Y').' a '.$record->deadline->format('d/m/Y'))
                ->columns(4)
                ->columnSpanFull()
                ->schema([
                    ViewEntry::make('bar')->hiddenLabel()->view('filament.goals.progress')->columnSpanFull(),
                    TextEntry::make('current')->label('Já tem')->weight('bold')->size('lg')
                        ->state(fn (Goal $record): string => MoneyFormatter::formatMinor($progress($record)->current)),
                    TextEntry::make('target')->label('Alvo')->state(fn (Goal $record): string => MoneyFormatter::formatMinor($record->target)),
                    TextEntry::make('remaining')->label('Falta')->state(fn (Goal $record): string => MoneyFormatter::formatMinor($progress($record)->remaining())),
                    TextEntry::make('pace')->label('Guardar por mês')
                        ->state(fn (Goal $record): string => ($p = $progress($record))->monthlyPace() > 0
                            ? MoneyFormatter::formatMinor($p->monthlyPace()).' por '.$p->monthsLeft().' mês(es)'
                            : '—'),
                    TextEntry::make('expected')->label('Esperado hoje (linha reta)')
                        ->state(fn (Goal $record): string => MoneyFormatter::formatMinor($progress($record)->expectedToday())),
                ]),
            Section::make('De onde vem o progresso')
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('sources')->hiddenLabel()->listWithLineBreaks()->bulleted()
                        ->state(fn (Goal $record): array => collect($progress($record)->sources)
                            ->map(fn (int $value, string $source): string => $source.': '.MoneyFormatter::formatMinor($value))
                            ->values()
                            ->all()),
                    TextEntry::make('missing')->hiddenLabel()->color('warning')
                        ->visible(fn (Goal $record): bool => $progress($record)->missing !== [])
                        ->state(fn (Goal $record): string => 'Fora do cálculo por falta de câmbio atualizado: '.implode(', ', $progress($record)->missing)),
                ]),
        ]);
    }
}
