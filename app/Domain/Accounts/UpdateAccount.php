<?php

namespace App\Domain\Accounts;

use App\Models\Account;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class UpdateAccount
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $actor, Account $account, array $data): Account
    {
        $validated = AccountRules::validate($data);

        if ($validated['visibility'] !== $account->visibility->value && $account->owner_id !== $actor->id) {
            throw ValidationException::withMessages([
                'visibility' => 'Só o dono da conta pode alterar a visibilidade.',
            ]);
        }

        $account->fill($validated)->save();

        return $account;
    }
}
