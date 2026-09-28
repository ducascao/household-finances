<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;

/**
 * Lançamento herda a visibilidade da conta.
 */
class TransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->current_household_id !== null;
    }

    public function view(User $user, Transaction $transaction): bool
    {
        return $this->canSeeAccount($user, $transaction);
    }

    public function create(User $user): bool
    {
        return $user->current_household_id !== null;
    }

    public function update(User $user, Transaction $transaction): bool
    {
        return $this->canSeeAccount($user, $transaction);
    }

    public function delete(User $user, Transaction $transaction): bool
    {
        return $this->canSeeAccount($user, $transaction);
    }

    public function deleteAny(User $user): bool
    {
        return $user->current_household_id !== null;
    }

    private function canSeeAccount(User $user, Transaction $transaction): bool
    {
        $account = Account::withoutGlobalScopes()->find($transaction->account_id);

        return $account !== null && $account->isVisibleTo($user);
    }
}
