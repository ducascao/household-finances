<?php

namespace App\Domain\Accounts;

use App\Models\Account;
use App\Models\User;

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
        $account->save();

        return $account;
    }
}
