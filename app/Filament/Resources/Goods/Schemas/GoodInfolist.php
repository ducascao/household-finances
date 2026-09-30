<?php

namespace App\Filament\Resources\Goods\Schemas;

use App\Domain\Goods\GoodValue;
use App\Models\Good;
use App\Support\MoneyFormatter;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class GoodInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(fn (Good $record): string => $record->name)
                ->description(fn (Good $record): string => $record->type->label().' · '.$record->visibility->label()
                    .' · adquirido em '.$record->acquisition_date->format('d/m/Y').' por '.MoneyFormatter::formatMinor($record->acquisition_value)
                    .($record->sale_date !== null ? ' · vendido em '.$record->sale_date->format('d/m/Y').' por '.MoneyFormatter::formatMinor((int) $record->sale_value) : ''))
                ->columns(4)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('current')->label('Valor atual')->weight('bold')->size('lg')
                        ->state(fn (Good $record): string => MoneyFormatter::formatMinor(GoodValue::at($record, today()))),
                    TextEntry::make('last')->label('Última avaliação')
                        ->state(fn (Good $record): string => GoodValue::lastValuation($record)?->date->format('d/m/Y') ?? 'nenhuma (vale o de aquisição)')
                        ->badge()
                        ->color(fn (Good $record): string => GoodValue::isStale($record) ? 'warning' : 'gray')
                        ->tooltip(fn (Good $record): ?string => GoodValue::isStale($record) ? 'Mais de 6 meses: informe um valor atualizado.' : null),
                    TextEntry::make('debt')->label('Saldo do financiamento')
                        ->state(fn (Good $record): string => $record->debt_id !== null ? MoneyFormatter::formatMinor(-GoodValue::linkedDebt($record)) : '—')
                        ->color('danger'),
                    TextEntry::make('net')->label('Valor líquido')->weight('bold')
                        ->state(fn (Good $record): string => MoneyFormatter::formatMinor(GoodValue::net($record))),
                    TextEntry::make('notes')->label('Observações')->columnSpanFull()->placeholder('—'),
                ]),
        ]);
    }
}
