<?php

namespace App\Filament\Pages;

use App\Domain\Investments\AssetReturn;
use App\Domain\Investments\Cdi;
use App\Domain\Investments\ReturnCalculator;
use App\Filament\Resources\Assets\AssetResource;
use App\Models\AssetOperation;
use App\Support\MoneyFormatter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Rentabilidade da carteira e de cada ativo num período (Dietz modificado), comparada ao CDI.
 */
class ReturnsPage extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions, InteractsWithSchemas, InteractsWithTable;

    protected static ?string $slug = 'rentabilidade';

    protected static ?string $title = 'Rentabilidade';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected static string|UnitEnum|null $navigationGroup = 'Investimentos';

    protected static ?int $navigationSort = 63;

    protected string $view = 'filament.portfolio.returns';

    public const PERIODS = [
        'month' => 'Mês atual',
        '12m' => 'Últimos 12 meses',
        'ytd' => 'Ano atual',
        'all' => 'Desde o início',
    ];

    #[Url]
    public string $period = '12m';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    /** @var array{assets: list<AssetReturn>, total: AssetReturn}|null */
    private ?array $result = null;

    /**
     * @return array{Carbon, Carbon}
     */
    public function range(): array
    {
        $today = today();

        return match ($this->period) {
            'month' => [$today->copy()->startOfMonth(), $today],
            'ytd' => [$today->copy()->startOfYear(), $today],
            'all' => [
                ($first = AssetOperation::query()->min('date')) !== null ? Carbon::parse($first) : $today->copy()->startOfYear(),
                $today,
            ],
            'custom' => [
                $this->from !== null ? Carbon::parse($this->from) : $today->copy()->subYear(),
                $this->to !== null ? Carbon::parse($this->to) : $today,
            ],
            default => [$today->copy()->subYear()->addDay(), $today],
        };
    }

    /**
     * @return array{assets: list<AssetReturn>, total: AssetReturn}
     */
    public function result(): array
    {
        [$from, $to] = $this->range();

        return $this->result ??= app(ReturnCalculator::class)->forPeriod($from, $to);
    }

    public function cdi(): ?float
    {
        [$from, $to] = $this->range();

        return app(Cdi::class)->accumulated($from, $to);
    }

    public function periodLabel(): string
    {
        [$from, $to] = $this->range();

        return ($this->period === 'custom' ? 'Período' : self::PERIODS[$this->period] ?? 'Período').': '.$from->format('d/m/Y').' a '.$to->format('d/m/Y');
    }

    public function money(int $minor): string
    {
        return MoneyFormatter::formatMinor($minor);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Por ativo')
            ->records(fn (): array => collect($this->result()['assets'])
                ->mapWithKeys(fn (AssetReturn $row): array => ['a'.$row->asset?->id => $this->record($row)])
                ->all())
            ->paginated(false)
            ->emptyStateHeading('Nenhum ativo com posição ou movimento no período')
            ->recordUrl(fn (array $record): string => AssetResource::getUrl('view', ['record' => $record['asset_id']]))
            ->columns([
                TextColumn::make('ticker')->label('Ativo')->weight('bold'),
                TextColumn::make('start')->label('Valor inicial')->alignEnd(),
                TextColumn::make('buys')->label('Compras')->alignEnd(),
                TextColumn::make('sells')->label('Vendas')->alignEnd(),
                TextColumn::make('incomes')->label('Proventos')->alignEnd(),
                TextColumn::make('end')->label('Valor final')->alignEnd(),
                TextColumn::make('result')->label('Resultado')->alignEnd()->weight('bold')
                    ->color(fn (array $record): string => $record['negative'] ? 'danger' : 'success'),
                TextColumn::make('percent')->label('Rentabilidade')->alignEnd()
                    ->color(fn (array $record): string => $record['negative'] ? 'danger' : 'success'),
            ]);
    }

    protected function getHeaderActions(): array
    {
        $presets = collect(self::PERIODS)->map(fn (string $label, string $key): Action => Action::make('period_'.$key)
            ->label($label)
            ->icon($this->period === $key ? Heroicon::OutlinedCheck : null)
            ->action(function () use ($key): void {
                $this->period = $key;
                $this->reset(['from', 'to']);
                $this->result = null;
            }))->values()->all();

        return [
            ActionGroup::make([
                ...$presets,
                Action::make('customPeriod')
                    ->label('Escolher datas…')
                    ->schema([
                        DatePicker::make('from')->label('De')->displayFormat('d/m/Y')->native(false)->required(),
                        DatePicker::make('to')->label('Até')->displayFormat('d/m/Y')->native(false)->required()->afterOrEqual('from'),
                    ])
                    ->action(function (array $data): void {
                        $this->period = 'custom';
                        $this->from = Carbon::parse($data['from'])->toDateString();
                        $this->to = Carbon::parse($data['to'])->toDateString();
                        $this->result = null;
                    }),
            ])
                ->label('Período')
                ->icon(Heroicon::OutlinedCalendar)
                ->button()
                ->color('gray'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function record(AssetReturn $row): array
    {
        $percent = $row->percent();

        return [
            'asset_id' => $row->asset?->id,
            'ticker' => $row->asset?->ticker,
            'start' => $this->money($row->startValue),
            'buys' => $row->buys > 0 ? $this->money($row->buys) : '—',
            'sells' => $row->sells > 0 ? $this->money($row->sells) : '—',
            'incomes' => $row->incomes > 0 ? $this->money($row->incomes) : '—',
            'end' => $this->money($row->endValue),
            'result' => $this->money($row->result()),
            'percent' => $percent !== null ? number_format($percent, 2, ',', '.').'%' : '—',
            'negative' => $row->result() < 0,
        ];
    }
}
