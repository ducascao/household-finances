<?php

namespace App\Filament\Resources\ImportBatches\RelationManagers;

use App\Domain\Import\CreateRuleFromLine;
use App\Domain\Import\Text;
use App\Domain\Import\UpdateImportLine;
use App\Enums\ImportLineAction;
use App\Enums\ImportLineStatus;
use App\Filament\Resources\ImportRules\Schemas\ImportRuleForm;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\ImportLine;
use App\Models\User;
use App\Support\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Linhas do lote: na revisão dá para escolher o que fazer, a categoria e a descrição de cada uma.
 */
class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Linhas do extrato';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $editable = fn (ImportLine $record): bool => $this->reviewing() && $record->status !== ImportLineStatus::Duplicate;

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['matchedTransaction']))
            ->defaultSort('line_number')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('date')
                    ->label('Data')
                    ->date('d/m/Y'),
                TextColumn::make('description')
                    ->label('No extrato')
                    ->wrap()
                    ->description(fn (ImportLine $record): ?string => $record->status === ImportLineStatus::Match && $record->matchedTransaction !== null
                        ? 'Previsto: '.$record->matchedTransaction->description.' (vence '.$record->matchedTransaction->due_date?->format('d/m/Y').')'
                        : null),
                TextColumn::make('amount')
                    ->label('Valor')
                    ->alignEnd()
                    ->color(fn (ImportLine $record): string => $record->amount->isNegative() ? 'danger' : 'success')
                    ->formatStateUsing(fn (ImportLine $record): string => MoneyFormatter::format($record->amount)),
                TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->formatStateUsing(fn (ImportLineStatus $state): string => $state->label())
                    ->color(fn (ImportLineStatus $state): string => match ($state) {
                        ImportLineStatus::New => 'info',
                        ImportLineStatus::Match => 'warning',
                        ImportLineStatus::Duplicate => 'gray',
                    }),
                SelectColumn::make('action')
                    ->label('Ação')
                    ->options(fn (ImportLine $record): array => $record->matched_transaction_id !== null
                        ? ImportLineAction::options()
                        : array_diff_key(ImportLineAction::options(), [ImportLineAction::Settle->value => true]))
                    ->selectablePlaceholder(false)
                    ->disabled(fn (ImportLine $record): bool => ! $editable($record))
                    ->updateStateUsing(fn (ImportLine $record, string $state) => $this->update($record, ['action' => $state])),
                SelectColumn::make('category_id')
                    ->label('Categoria')
                    ->options(fn (): array => $this->categoryOptions())
                    ->disabled(fn (ImportLine $record): bool => ! $editable($record) || $record->action !== ImportLineAction::Import)
                    ->updateStateUsing(fn (ImportLine $record, ?string $state) => $this->update($record, ['category_id' => $state])),
                TextInputColumn::make('final_description')
                    ->label('Descrição do lançamento')
                    ->disabled(fn (ImportLine $record): bool => ! $editable($record) || $record->action !== ImportLineAction::Import)
                    ->updateStateUsing(fn (ImportLine $record, ?string $state) => $this->update($record, ['final_description' => $state])),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Situação')
                    ->options(ImportLineStatus::options()),
                SelectFilter::make('action')
                    ->label('Ação')
                    ->options(ImportLineAction::options()),
            ])
            ->recordActions([
                Action::make('createRule')
                    ->label('Criar regra')
                    ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                    ->color('gray')
                    ->visible(fn (ImportLine $record): bool => $this->reviewing() && $record->status === ImportLineStatus::New)
                    ->modalHeading('Criar regra de importação')
                    ->modalDescription('A regra vale para as próximas importações e já é aplicada às linhas deste lote que ainda não têm regra.')
                    ->fillForm(fn (ImportLine $record): array => [
                        'pattern' => Text::normalize($record->description),
                        'category_id' => $record->category_id,
                        'description' => $record->final_description !== $record->description ? $record->final_description : null,
                    ])
                    ->schema(ImportRuleForm::fields())
                    ->action(function (ImportLine $record, array $data, Action $action): void {
                        try {
                            app(CreateRuleFromLine::class)->execute($this->user(), $record, $data);
                        } catch (ValidationException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                            $action->halt();

                            return;
                        }

                        Notification::make()->success()->title('Regra criada e aplicada ao lote.')->send();
                    }),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function update(ImportLine $line, array $data): mixed
    {
        try {
            app(UpdateImportLine::class)->execute($this->user(), $line, $data);
        } catch (ValidationException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }

        return null;
    }

    private function reviewing(): bool
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof ImportBatch && $owner->isReviewing();
    }

    /**
     * @return array<int, string>
     */
    private function categoryOptions(): array
    {
        return Category::query()->with('parent')->get()
            ->sortBy(fn (Category $category): string => $category->type->value.$category->fullName())
            ->mapWithKeys(fn (Category $category): array => [$category->id => $category->fullName()])
            ->all();
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }
}
