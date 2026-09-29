<?php

namespace App\Filament\Resources\Assets\RelationManagers;

use App\Domain\Investments\ManageIncomes;
use App\Enums\AssetIncomeType;
use App\Enums\CategoryType;
use App\Filament\Forms\MoneyInput;
use App\Models\Asset;
use App\Models\AssetIncome;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Support\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class IncomesRelationManager extends RelationManager
{
    protected static string $relationship = 'incomes';

    protected static ?string $title = 'Proventos';

    /**
     * Só para ativos da B3 (renda fixa e previdência usam saldos informados).
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Asset && ! $ownerRecord->type->isValuedByBalance();
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('type')
                ->label('Tipo')
                ->options(AssetIncomeType::options())
                ->default(AssetIncomeType::Dividend->value)
                ->required(),
            DatePicker::make('date')
                ->label('Data de pagamento')
                ->displayFormat('d/m/Y')
                ->native(false)
                ->default(now())
                ->required(),
            MoneyInput::make('gross_amount')
                ->label('Valor bruto')
                ->required(),
            MoneyInput::make('withheld_tax')
                ->label('IR retido')
                ->default(0)
                ->helperText('JCP normalmente tem 15% retido na fonte.'),
            Select::make('category_id')
                ->label('Categoria')
                ->placeholder('Automática pelo tipo')
                ->options(fn (): array => Category::query()->where('type', CategoryType::Income->value)->with('parent')->get()
                    ->mapWithKeys(fn (Category $category): array => [$category->id => $category->fullName()])
                    ->sort()
                    ->all()),
            Textarea::make('notes')
                ->label('Observações')
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort(fn ($query) => $query->orderByDesc('date')->orderByDesc('id'))
            ->columns([
                TextColumn::make('date')->label('Pagamento')->date('d/m/Y'),
                TextColumn::make('type')->label('Tipo')->badge()->formatStateUsing(fn (AssetIncomeType $state): string => $state->label()),
                TextColumn::make('gross_amount')->label('Bruto')->alignEnd()->formatStateUsing(fn (int $state): string => MoneyFormatter::formatMinor($state)),
                TextColumn::make('withheld_tax')->label('IR')->alignEnd()->formatStateUsing(fn (int $state): string => $state === 0 ? '—' : MoneyFormatter::formatMinor($state)),
                TextColumn::make('net')->label('Líquido')->alignEnd()->weight('bold')
                    ->state(fn (AssetIncome $record): string => MoneyFormatter::formatMinor($record->netAmount())),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Lançar provento')
                    ->modalHeading('Lançar provento')
                    ->using(fn (array $data, Action $action): Model => $this->run($action, fn () => app(ManageIncomes::class)->register($this->user(), $this->asset(), $data))),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data, AssetIncome $record): array => [
                        ...$data,
                        'category_id' => Transaction::withoutGlobalScopes()->where('asset_income_id', $record->id)->value('category_id'),
                    ])
                    ->using(fn (AssetIncome $record, array $data, Action $action): Model => $this->run($action, fn () => app(ManageIncomes::class)->update($this->user(), $record, $data))),
                DeleteAction::make()
                    ->modalDescription('A receita lançada na corretora também é excluída.')
                    ->using(function (AssetIncome $record, Action $action): bool {
                        $this->run($action, fn () => app(ManageIncomes::class)->delete($this->user(), $record));

                        return true;
                    }),
            ]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function run(Action $action, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('Provento não registrado')->body($e->getMessage())->persistent()->send();
            $action->halt();

            throw $e; // halt() já interrompe; não chega aqui.
        }
    }

    private function asset(): Asset
    {
        /** @var Asset */
        return $this->getOwnerRecord();
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
