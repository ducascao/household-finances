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

        $b3 = fn (Asset $record): bool => ! $record->type->isValuedByBalance();
        $balance = fn (Asset $record): bool => $record->type->isValuedByBalance();
        $result = fn (Asset $record): string => ($r = $row($record)) !== null
            ? MoneyFormatter::format($r->result()).($r->resultPercent() !== null ? ' ('.number_format($r->resultPercent(), 2, ',', '.').'%)' : '')
            : '—';
        $resultColor = fn (Asset $record): ?string => ($r = $row($record)) !== null ? ($r->result()->isNegative() ? 'danger' : 'success') : null;

        return $schema->components([
            Section::make(fn (Asset $record): string => $record->ticker !== null ? "{$record->ticker} — {$record->name}" : $record->name)
                ->columns(4)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('type')->label('Tipo')->formatStateUsing(fn (Asset $record): string => $record->type->label()),
                    TextEntry::make('account.name')->label(fn (Asset $record): string => $record->type->isValuedByBalance() ? 'Conta' : 'Corretora'),

                    // Ativos da B3
                    TextEntry::make('quantity')->label('Quantidade')->visible($b3)
                        ->state(fn (Asset $record): string => Quantity::format((string) $row($record)?->position->quantity)),
                    TextEntry::make('average')->label('Preço médio')->visible($b3)
                        ->state(fn (Asset $record): string => 'R$ '.Quantity::format((string) $row($record)?->position->averagePrice, 2)),
                    TextEntry::make('cost')->label('Custo total')->visible($b3)
                        ->state(fn (Asset $record): string => $row($record) !== null ? MoneyFormatter::format($row($record)->cost()) : '—'),
                    TextEntry::make('price')->label('Última cotação')->visible($b3)
                        ->state(fn (Asset $record): string => ($r = $row($record))?->lastPrice !== null
                            ? 'R$ '.Quantity::format($r->lastPrice->price, 2).' em '.$r->lastPrice->date->format('d/m/Y')
                            : 'sem cotação'),
                    TextEntry::make('market')->label('Valor de mercado')->visible($b3)
                        ->state(fn (Asset $record): string => $row($record) !== null ? MoneyFormatter::format($row($record)->marketValue()) : '—'),
                    TextEntry::make('result')->label('Resultado não realizado')->visible($b3)->state($result)->color($resultColor),

                    // Renda fixa e previdência
                    TextEntry::make('issuer')->label('Emissor / seguradora')->visible($balance)->placeholder('—'),
                    TextEntry::make('indexer')->label('Indexador · taxa')->visible($balance)
                        ->state(fn (Asset $record): string => trim(($record->indexer?->label() ?? '').($record->rate !== null ? ' · '.$record->rate : ''), ' ·') ?: '—'),
                    TextEntry::make('maturity_date')->label('Vencimento')->visible($balance)->date('d/m/Y')->placeholder('—'),
                    TextEntry::make('invested')->label('Investido (aportes − resgates)')->visible($balance)
                        ->state(fn (Asset $record): string => $row($record) !== null ? MoneyFormatter::format($row($record)->cost()) : '—'),
                    TextEntry::make('current')->label('Valor atual')->visible($balance)
                        ->state(fn (Asset $record): string => $row($record) !== null ? MoneyFormatter::format($row($record)->marketValue()) : '—')
                        ->helperText(fn (Asset $record): ?string => ($r = $row($record))?->valuation === null ? null : match (true) {
                            $r->valuation->lastValuation === null => 'Sem saldo informado: considera só os aportes.',
                            $r->valuation->estimated => 'Estimado: saldo de '.$r->valuation->lastValuation->date->format('d/m/Y').' + aportes/resgates depois dele.',
                            default => 'Saldo informado em '.$r->valuation->lastValuation->date->format('d/m/Y').'.',
                        }),
                    TextEntry::make('yield')->label('Rendimento')->visible($balance)->state($result)->color($resultColor),
                ]),
        ]);
    }
}
