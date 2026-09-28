<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Domain\Accounts\CreateAccount as CreateAccountAction;
use App\Filament\Concerns\RunsDomainActions;
use App\Filament\Resources\Accounts\AccountResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateAccount extends CreateRecord
{
    use RunsDomainActions;

    protected static string $resource = AccountResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $user */
        $user = auth()->user();

        return $this->runDomainAction(fn () => app(CreateAccountAction::class)->execute($user, $data));
    }
}
