<?php

namespace App\Filament\Resources\Assets\Schemas;

use App\Domain\Investments\Portfolio;
use App\Domain\Investments\PortfolioRow;
use App\Domain\Investments\Quantity;
use App\Models\Asset;
use App\Support\MoneyFormatter;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AssetInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $row = fn (Asset $asset): ?PortfolioRow => collect(app(Portfolio::class)->rows(onlyOpen: false))
            ->first(fn (PortfolioRow $row): bool => $row->asset->is($asset));

        return $schema->components([
            Section::make(fn (Asset $record): string => "{$record->ticker} — {$record->name}")
                ->columns(4)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('type')->label('Tipo')->formatStateUsing(fn (Asset $record): string => $record->type->label()),
                    TextEntry::make('account.name')->label('Corretora'),
                    TextEntry::make('quantity')->label('Quantidade')
                        ->state(fn (Asset $record): string => Quantity::format((string) $row($record)?->position->quantity)),
                    TextEntry::make('average')->label('Preço médio')
                        ->state(fn (Asset $record): string => 'R$ '.Quantity::format((string) $row($record)?->position->averagePrice, 2)),
                    TextEntry::make('cost')->label('Custo total')
                        ->state(fn (Asset $record): string => $row($record) !== null ? MoneyFormatter::format($row($record)->cost()) : '—'),
                    TextEntry::make('price')->label('Última cotação')
                        ->state(fn (Asset $record): string => ($r = $row($record))?->lastPrice !== null
                            ? 'R$ '.Quantity::format($r->lastPrice->price, 2).' em '.$r->lastPrice->date->format('d/m/Y')
                            : 'sem cotação'),
                    TextEntry::make('market')->label('Valor de mercado')
                        ->state(fn (Asset $record): string => $row($record) !== null ? MoneyFormatter::format($row($record)->marketValue()) : '—'),
                    TextEntry::make('result')->label('Resultado não realizado')
                        ->state(fn (Asset $record): string => ($r = $row($record)) !== null
                            ? MoneyFormatter::format($r->result()).($r->resultPercent() !== null ? ' ('.number_format($r->resultPercent(), 2, ',', '.').'%)' : '')
                            : '—')
                        ->color(fn (Asset $record): ?string => ($r = $row($record)) !== null ? ($r->result()->isNegative() ? 'danger' : 'success') : null),
                ]),
        ]);
    }
}
