<?php

namespace App\Filament\Resources\Transactions\Actions;

use App\Domain\Transactions\DeleteTransaction;
use App\Domain\Transfers\CreateTransfer;
use App\Domain\Transfers\TransferLegs;
use App\Domain\Transfers\UpdateTransfer;
use App\Filament\Resources\Transactions\Schemas\TransferForm;
use App\Models\Transaction;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class TransferActions
{
    public static function create(): Action
    {
        return Action::make('createTransfer')
            ->label('Nova transferência')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('gray')
            ->modalHeading('Nova transferência')
            ->schema(fn (Schema $schema): Schema => $schema->components(TransferForm::fields())->columns(2))
            ->action(function (array $data, Action $action): void {
                self::run($action, fn () => app(CreateTransfer::class)->execute(self::user(), $data));

                Notification::make()->success()->title('Transferência registrada.')->send();
            });
    }

    public static function edit(): Action
    {
        return Action::make('editTransfer')
            ->label('Editar')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->modalHeading('Editar transferência')
            ->visible(fn (Transaction $record): bool => $record->isTransfer())
            ->authorize(fn (Transaction $record): bool => self::user()->can('update', $record))
            ->fillForm(fn (Transaction $record): array => TransferForm::fillFrom($record))
            ->schema(function (Schema $schema, Transaction $record): Schema {
                $legs = TransferLegs::of($record);

                return $schema->components(TransferForm::fields([$legs->out->account_id, $legs->in->account_id]))->columns(2);
            })
            ->action(function (Transaction $record, array $data, Action $action): void {
                self::run($action, fn () => app(UpdateTransfer::class)->execute(self::user(), $record, $data));

                Notification::make()->success()->title('Transferência atualizada.')->send();
            });
    }

    /**
     * Exclusão de um lançamento; numa transferência, exclui o par.
     */
    public static function delete(): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription(fn (Transaction $record): ?string => $record->isTransfer()
                ? 'É uma transferência: os dois lançamentos (origem e destino) serão excluídos.'
                : null)
            ->using(function (Transaction $record, DeleteAction $action): bool {
                self::run($action, fn () => app(DeleteTransaction::class)->execute(self::user(), $record));

                return true;
            });
    }

    public static function bulkDelete(): BulkAction
    {
        return BulkAction::make('deleteSelected')
            ->label('Excluir selecionados')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Transferências selecionadas são excluídas por inteiro (origem e destino).')
            ->action(function (Collection $records): void {
                /** @var Collection<int, Transaction> $records */
                try {
                    $count = app(DeleteTransaction::class)->executeMany(self::user(), $records);
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title($count === 1 ? '1 lançamento excluído.' : "{$count} lançamentos excluídos.")->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * @param  callable(): mixed  $callback
     */
    private static function run(Action $action, callable $callback): void
    {
        try {
            $callback();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $action->halt();
        }
    }

    private static function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
