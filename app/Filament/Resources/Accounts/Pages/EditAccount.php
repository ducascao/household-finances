<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Domain\Accounts\UpdateAccount;
use App\Filament\Concerns\RunsDomainActions;
use App\Filament\Resources\Accounts\AccountResource;
use App\Models\Account;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditAccount extends EditRecord
{
    use RunsDomainActions;

    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  Account  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $user */
        $user = auth()->user();

        return $this->runDomainAction(fn () => app(UpdateAccount::class)->execute($user, $record, $data));
    }
}
