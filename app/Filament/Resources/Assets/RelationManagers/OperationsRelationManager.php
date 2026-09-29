<?php

namespace App\Filament\Resources\Assets\RelationManagers;

use App\Domain\Investments\ManageOperations;
use App\Domain\Investments\PositionCalculator;
use App\Domain\Investments\Quantity;
use App\Enums\AssetOperationType;
use App\Filament\Resources\Assets\Schemas\OperationForm;
use App\Models\Asset;
use App\Models\AssetOperation;
use App\Models\User;
use App\Support\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class OperationsRelationManager extends RelationManager
{
    protected static string $relationship = 'operations';

    protected static ?string $title = 'Operações';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(OperationForm::fields())->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort(fn ($query) => $query->orderByDesc('date')->orderByDesc('id'))
            ->columns([
                TextColumn::make('date')->label('Data')->date('d/m/Y'),
                TextColumn::make('type')->label('Operação')->badge()
                    ->formatStateUsing(fn (AssetOperationType $state): string => $state->label())
                    ->color(fn (AssetOperationType $state): string => match ($state) {
                        AssetOperationType::Buy => 'info',
                        AssetOperationType::Sell => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('quantity')->label('Quantidade')->alignEnd()
                    ->formatStateUsing(fn (AssetOperation $record): string => $record->type->isTrade()
                        ? Quantity::format((string) $record->quantity)
                        : ($record->type === AssetOperationType::Split ? '1 → ' : '').Quantity::format((string) $record->factor).($record->type === AssetOperationType::ReverseSplit ? ' → 1' : '')),
                TextColumn::make('unit_price')->label('Preço')->alignEnd()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : 'R$ '.Quantity::format($state, 2)),
                TextColumn::make('fees')->label('Taxas')->alignEnd()
                    ->formatStateUsing(fn (int $state): string => $state === 0 ? '—' : MoneyFormatter::formatMinor($state)),
                TextColumn::make('total')->label('Valor na conta')->alignEnd()
                    ->state(fn (AssetOperation $record): string => $record->type->isTrade() ? MoneyFormatter::formatMinor(ManageOperations::cashAmount($record)) : '—'),
                TextColumn::make('realized')->label('Resultado realizado')->alignEnd()
                    ->state(fn (AssetOperation $record): string => ($result = $this->realized()[$record->id] ?? null) !== null ? MoneyFormatter::formatMinor($result) : '—')
                    ->color(fn (AssetOperation $record): ?string => ($result = $this->realized()[$record->id] ?? null) === null ? null : ($result < 0 ? 'danger' : 'success')),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Lançar operação')
                    ->modalHeading('Lançar operação')
                    ->using(fn (array $data, Action $action): Model => $this->run($action, fn () => app(ManageOperations::class)->register($this->user(), $this->asset(), $data))),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data): array => [
                        ...$data,
                        'quantity' => $data['quantity'] !== null ? Quantity::format((string) $data['quantity']) : null,
                        'unit_price' => $data['unit_price'] !== null ? Quantity::format((string) $data['unit_price'], 2) : null,
                        'factor' => $data['factor'] !== null ? Quantity::format((string) $data['factor']) : null,
                    ])
                    ->using(fn (AssetOperation $record, array $data, Action $action): Model => $this->run($action, fn () => app(ManageOperations::class)->update($this->user(), $record, $data))),
                DeleteAction::make()
                    ->modalDescription('O lançamento na corretora gerado pela operação também é excluído.')
                    ->using(function (AssetOperation $record, Action $action): bool {
                        $this->run($action, fn () => app(ManageOperations::class)->delete($this->user(), $record));

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
            Notification::make()->danger()->title('Operação não registrada')->body($e->getMessage())->persistent()->send();
            $action->halt();

            throw $e; // halt() já interrompe; não chega aqui.
        }
    }

    /** @var array<int, int>|null */
    private ?array $realizedCache = null;

    /**
     * Resultado realizado (centavos) de cada venda, por id da operação.
     *
     * @return array<int, int>
     */
    private function realized(): array
    {
        if ($this->realizedCache !== null) {
            return $this->realizedCache;
        }

        $sales = app(PositionCalculator::class)->history(AssetOperation::where('asset_id', $this->asset()->id)->get())['sales'];

        return $this->realizedCache = collect($sales)->mapWithKeys(fn ($sale): array => [$sale->operation->id => $sale->resultMinor()])->all();
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
