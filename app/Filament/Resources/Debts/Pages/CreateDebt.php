<?php

namespace App\Filament\Resources\Debts\Pages;

use App\Domain\Debts\ManageDebts;
use App\Filament\Concerns\RunsDomainActions;
use App\Filament\Resources\Debts\DebtResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateDebt extends CreateRecord
{
    use RunsDomainActions;

    protected static string $resource = DebtResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $user */
        $user = auth()->user();

        return $this->runDomainAction(fn () => app(ManageDebts::class)->create($user, $data));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
