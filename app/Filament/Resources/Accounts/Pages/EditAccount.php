<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Domain\Accounts\UpdateAccount;
use App\Filament\Concerns\RunsDomainActions;
use App\Filament\Resources\Accounts\AccountResource;
use App\Models\Account;
use App\Models\CreditCard;
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
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Account $account */
        $account = $this->getRecord();
        $card = CreditCard::withoutGlobalScopes()->where('account_id', $account->id)->first();

        if ($card !== null) {
            $data['card_closing_day'] = $card->closing_day;
            $data['card_due_day'] = $card->due_day;
            $data['card_limit'] = $card->limit->getMinorAmount()->toInt();
        }

        return $data;
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
