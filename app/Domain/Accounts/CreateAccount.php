<?php

namespace App\Domain\Accounts;

use App\Domain\CreditCard\SaveCreditCard;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateAccount
{
    /**
     * Cria a conta no lar atual do usuário, que fica como dono.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(User $owner, array $data): Account
    {
        $validated = AccountRules::validate($data);

        $account = new Account($validated);
        $account->household_id = (int) $owner->current_household_id;
        $account->owner_id = $owner->id;

        return DB::transaction(function () use ($account, $data): Account {
            $account->save();
            app(SaveCreditCard::class)->execute($account, $data);

            return $account;
        });
    }
}
