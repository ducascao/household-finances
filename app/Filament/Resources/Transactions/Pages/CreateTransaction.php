<?php

namespace App\Filament\Resources\Transactions\Pages;

use App\Domain\Transactions\CreateTransaction as CreateTransactionAction;
use App\Filament\Concerns\RunsDomainActions;
use App\Filament\Resources\Transactions\TransactionResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTransaction extends CreateRecord
{
    use RunsDomainActions;

    protected static string $resource = TransactionResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $user */
        $user = auth()->user();

        return $this->runDomainAction(fn () => app(CreateTransactionAction::class)->execute($user, $data));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
