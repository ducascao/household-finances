<?php

namespace App\Filament\Pages;

use App\Domain\Investments\Portfolio;
use App\Domain\Investments\PortfolioRow;
use App\Domain\Investments\PriceBook;
use App\Domain\Investments\Quantity;
use App\Filament\Portfolio\PortfolioDistributionWidget;
use App\Filament\Portfolio\PortfolioTotalsWidget;
use App\Filament\Resources\Assets\AssetResource;
use App\Support\MoneyFormatter;
use BackedEnum;
use Brick\Money\Money;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Carteira B3: posições abertas com preço médio, última cotação, valor de mercado e resultado não realizado.
 */
class PortfolioPage extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions, InteractsWithSchemas, InteractsWithTable;

    protected static ?string $slug = 'carteira';

    protected static ?string $title = 'Carteira';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Investimentos';

    protected static ?int $navigationSort = 61;

    protected string $view = 'filament.portfolio.page';

    protected function getHeaderWidgets(): array
    {
        return [PortfolioTotalsWidget::class, PortfolioDistributionWidget::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Posições')
            ->records(function (): array {
                $portfolio = app(Portfolio::class);
                $rows = $portfolio->rows();
                $totalMarket = $portfolio->totals($rows)['market'];

                return collect($rows)
                    ->mapWithKeys(fn (PortfolioRow $row): array => ['a'.$row->asset->id => $this->record($row, $totalMarket)])
                    ->all();
            })
            ->paginated(false)
            ->emptyStateHeading('Nenhuma posição aberta')
            ->emptyStateDescription('Cadastre um ativo e lance a primeira compra.')
            ->recordUrl(fn (array $record): string => AssetResource::getUrl('view', ['record' => $record['asset_id']]))
            ->columns([
                TextColumn::make('ticker')->label('Ativo')->weight('bold')->description(fn (array $record): string => $record['type']),
                TextColumn::make('quantity')->label('Quantidade')->alignEnd(),
                TextColumn::make('average')->label('Preço médio')->alignEnd(),
                TextColumn::make('cost')->label('Custo')->alignEnd(),
                TextColumn::make('price')->label('Cotação')->alignEnd()
                    ->description(fn (array $record): ?string => $record['price_date'])
                    ->icon(fn (array $record): ?string => $record['stale'] ? 'heroicon-m-exclamation-triangle' : null)
                    ->iconColor('warning')
                    ->tooltip(fn (array $record): ?string => $record['stale'] ? 'Cotação ausente ou com mais de 7 dias' : null),
                TextColumn::make('market')->label('Valor de mercado')->alignEnd()->weight('bold'),
                TextColumn::make('result')->label('Resultado')->alignEnd()
                    ->description(fn (array $record): ?string => $record['result_percent'])
                    ->color(fn (array $record): string => $record['negative'] ? 'danger' : 'success'),
                TextColumn::make('share')->label('% da carteira')->alignEnd(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fetchQuotes')
                ->label('Atualizar cotações')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(function (): void {
                    $result = app(PriceBook::class)->fetchAll();

                    Notification::make()
                        ->title("{$result['updated']} cotação(ões) atualizada(s)")
                        ->body($result['missing'] !== [] ? 'Sem cotação (mantida a última): '.implode(', ', $result['missing']) : null)
                        ->color($result['missing'] === [] ? 'success' : 'warning')
                        ->send();
                }),
            Action::make('newAsset')
                ->label('Novo ativo')
                ->icon(Heroicon::OutlinedPlus)
                ->url(AssetResource::getUrl('create')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function record(PortfolioRow $row, Money $totalMarket): array
    {
        $market = $row->marketValue();
        $percent = $row->resultPercent();

        return [
            'asset_id' => $row->asset->id,
            'ticker' => $row->asset->ticker,
            'type' => $row->asset->type->label().' · '.$row->asset->account->name,
            'quantity' => Quantity::format((string) $row->position->quantity),
            'average' => 'R$ '.Quantity::format((string) $row->position->averagePrice, 2),
            'cost' => MoneyFormatter::format($row->cost()),
            'price' => $row->lastPrice !== null ? 'R$ '.Quantity::format($row->lastPrice->price, 2) : 'sem cotação',
            'price_date' => $row->lastPrice?->date->format('d/m/Y'),
            'stale' => $row->isPriceStale(),
            'market' => MoneyFormatter::format($market),
            'result' => MoneyFormatter::format($row->result()),
            'result_percent' => $percent !== null ? number_format($percent, 2, ',', '.').'%' : null,
            'negative' => $row->result()->isNegative(),
            'share' => $totalMarket->isPositive()
                ? number_format((float) (string) $market->getAmount() / (float) (string) $totalMarket->getAmount() * 100, 1, ',', '.').'%'
                : '—',
        ];
    }
}
