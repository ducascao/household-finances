<?php

namespace App\Filament\Resources\Transactions\Pages;

use App\Domain\Transactions\UpdateTransaction;
use App\Enums\CategoryType;
use App\Filament\Concerns\RunsDomainActions;
use App\Filament\Resources\Transactions\Actions\TransferActions;
use App\Filament\Resources\Transactions\TransactionResource;
use App\Models\Transaction;
use App\Models\User;
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
            TransferActions::delete(),
        ];
    }

    /**
     * Transferência é editada pelo modal de transferência na lista.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        if ($this->record->isTransfer() || $this->record->installment_group_id !== null) {
            $this->redirect(static::getResource()::getUrl('index'));
        }
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
        // Despesa com valor positivo é estorno.
        $data['is_refund'] = $this->record->category?->type === CategoryType::Expense && ! $this->record->amount->isNegative();

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
