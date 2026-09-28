<?php

namespace App\Filament\Resources\Transactions\Pages;

use App\Domain\Transactions\UpdateTransaction;
use App\Filament\Concerns\RunsDomainActions;
use App\Filament\Resources\Transactions\TransactionResource;
use App\Models\Transaction;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * @property Transaction $record
 */
class EditTransaction extends EditRecord
{
    use RunsDomainActions;

    protected static string $resource = TransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * O formulário trabalha com o valor sem sinal; o sinal vem da categoria.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['amount'] = $this->record->amount->abs()->getMinorAmount()->toInt();

        return $data;
    }

    /**
     * @param  Transaction  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $user */
        $user = auth()->user();

        return $this->runDomainAction(fn () => app(UpdateTransaction::class)->execute($user, $record, $data));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
