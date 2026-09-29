<?php

namespace App\Filament\Pages;

use App\Domain\Budgets\BudgetReport;
use App\Domain\Budgets\BudgetRow;
use App\Domain\Budgets\CopyPreviousMonth;
use App\Domain\Budgets\SaveBudget;
use App\Enums\CategoryType;
use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\Transactions\TransactionResource;
use App\Models\Category;
use App\Models\User;
use App\Support\MoneyFormatter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Url;

/**
 * Orçado × realizado do mês, por categoria de despesa. O orçado é editado na própria linha.
 */
class Budgets extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions, InteractsWithSchemas, InteractsWithTable;

    protected static ?string $slug = 'orcamento';

    protected static ?string $title = 'Orçamento';

    protected static ?string $navigationLabel = 'Orçamento';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.budgets.page';

    #[Url]
    public string $month = '';

    public function mount(): void
    {
        if (preg_match('/^\d{4}-\d{2}$/', $this->month) !== 1) {
            $this->month = today()->format('Y-m');
        }
    }

    public function getSubheading(): string
    {
        return ucfirst($this->selectedMonth()->locale('pt_BR')->translatedFormat('F/Y'))
            .' — realizado por competência (compras no cartão contam no mês da fatura). A situação considera o realizado + o previsto.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->rows())
            ->paginated(false)
            ->emptyStateHeading('Nenhum orçamento nem gasto neste mês')
            ->emptyStateDescription('Use "Definir orçamento" ou "Copiar do mês anterior".')
            ->recordUrl(fn (array $record): string => TransactionResource::getUrl('index', [
                'filters' => [
                    'category_id' => ['value' => (string) $record['category_id']],
                    'competence' => ['value' => $this->month],
                ],
            ]))
            ->columns([
                TextColumn::make('name')
                    ->label('Categoria')
                    ->weight(fn (array $record): ?string => $record['is_root'] ? 'bold' : null)
                    ->description(fn (array $record): ?string => match (true) {
                        $record['children_over_budget'] => 'Subcategorias somam mais que o orçado da principal',
                        $record['planned_from_children'] => 'Orçado = soma das subcategorias',
                        default => null,
                    }),
                TextInputColumn::make('budget')
                    ->label('Orçado (R$)')
                    ->placeholder('—')
                    ->alignEnd()
                    ->extraInputAttributes(['inputmode' => 'decimal', 'style' => 'text-align: right; min-width: 8rem;'])
                    ->updateStateUsing(function (array $record, ?string $state): ?string {
                        $this->saveBudget((int) $record['category_id'], $state);

                        return $state;
                    }),
                TextColumn::make('paid')
                    ->label('Realizado')
                    ->alignEnd()
                    ->formatStateUsing(fn (int $state): string => MoneyFormatter::formatMinor($state)),
                TextColumn::make('scheduled')
                    ->label('Previsto')
                    ->alignEnd()
                    ->color('gray')
                    ->formatStateUsing(fn (int $state): string => $state === 0 ? '—' : MoneyFormatter::formatMinor($state)),
                TextColumn::make('available')
                    ->label('Disponível')
                    ->alignEnd()
                    ->placeholder('—')
                    ->color(fn (array $record): ?string => ($record['available'] ?? 0) < 0 ? 'danger' : null)
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : MoneyFormatter::formatMinor($state)),
                ViewColumn::make('percent')
                    ->label('Uso do orçamento')
                    ->view('filament.budgets.progress'),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('previousMonth')
                ->label('Mês anterior')
                ->icon(Heroicon::OutlinedChevronLeft)
                ->color('gray')
                ->action(fn () => $this->month = $this->selectedMonth()->subMonthNoOverflow()->format('Y-m')),
            Action::make('nextMonth')
                ->label('Próximo mês')
                ->icon(Heroicon::OutlinedChevronRight)
                ->iconPosition('after')
                ->color('gray')
                ->action(fn () => $this->month = $this->selectedMonth()->addMonthNoOverflow()->format('Y-m')),
            Action::make('copyPreviousMonth')
                ->label('Copiar do mês anterior')
                ->icon(Heroicon::OutlinedDocumentDuplicate)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Copia os valores do mês anterior só para as categorias que ainda não têm orçamento neste mês.')
                ->action(function (): void {
                    $copied = app(CopyPreviousMonth::class)->execute($this->householdId(), $this->selectedMonth());

                    Notification::make()->success()
                        ->title($copied === 0 ? 'Nada a copiar.' : "{$copied} orçamento(s) copiado(s).")
                        ->send();
                }),
            Action::make('defineBudget')
                ->label('Definir orçamento')
                ->icon(Heroicon::OutlinedPlus)
                ->schema([
                    Select::make('category_id')
                        ->label('Categoria')
                        ->options(fn (): array => Category::query()
                            ->where('type', CategoryType::Expense->value)
                            ->with('parent')
                            ->get()
                            ->sortBy(fn (Category $category): string => $category->fullName())
                            ->mapWithKeys(fn (Category $category): array => [$category->id => $category->fullName()])
                            ->all())
                        ->searchable()
                        ->required(),
                    MoneyInput::make('amount')
                        ->label('Valor orçado')
                        ->required(),
                ])
                ->action(function (array $data, Action $action): void {
                    try {
                        app(SaveBudget::class)->execute($this->householdId(), (int) $data['category_id'], $this->selectedMonth(), (int) $data['amount']);
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                        $action->halt();
                    }
                }),
        ];
    }

    public function selectedMonth(): Carbon
    {
        return Carbon::createFromFormat('!Y-m', $this->month)?->startOfMonth() ?? today()->startOfMonth();
    }

    private function saveBudget(int $categoryId, ?string $state): void
    {
        try {
            $amount = $state === null || trim($state) === '' ? null : MoneyFormatter::parseToMinor($state);
            app(SaveBudget::class)->execute($this->householdId(), $categoryId, $this->selectedMonth(), $amount);
        } catch (InvalidArgumentException) {
            Notification::make()->danger()->title('Valor inválido. Use o formato 1.234,56.')->send();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rows(): array
    {
        $rows = [];

        foreach (app(BudgetReport::class)->forMonth($this->householdId(), $this->selectedMonth()) as $root) {
            $rows['c'.$root->category->id] = $this->toRecord($root, isRoot: true);

            foreach ($root->children as $child) {
                $rows['c'.$child->category->id] = $this->toRecord($child, isRoot: false);
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function toRecord(BudgetRow $row, bool $isRoot): array
    {
        $status = $row->status();

        return [
            'category_id' => $row->category->id,
            'name' => $isRoot ? $row->category->name : '↳ '.$row->category->name,
            'is_root' => $isRoot,
            'budget' => $row->budget === null ? null : ltrim(str_replace('R$ ', '', MoneyFormatter::formatMinor($row->budget))),
            'planned_from_children' => $row->budget === null && $row->planned !== null,
            'children_over_budget' => $row->childrenOverBudget,
            'paid' => $row->paid,
            'scheduled' => $row->scheduled,
            'available' => $row->available(),
            'percent' => $row->percent(),
            'status' => $status?->value,
            'status_label' => $status?->label(),
            'status_color' => $status?->color(),
        ];
    }

    private function householdId(): int
    {
        /** @var User $user */
        $user = auth()->user();

        return (int) $user->current_household_id;
    }
}
