<?php

namespace App\Domain\Accounts;

use App\Domain\CreditCard\SaveCreditCard;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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

        if ($validated['currency'] !== $account->currency && $account->transactions()->withoutGlobalScopes()->exists()) {
            throw ValidationException::withMessages([
                'currency' => 'Não é possível mudar a moeda de uma conta com lançamentos.',
            ]);
        }

        DB::transaction(function () use ($account, $validated, $data): void {
            $account->fill($validated)->save();
            app(SaveCreditCard::class)->execute($account, $data);
        });

        return $account;
    }
}
