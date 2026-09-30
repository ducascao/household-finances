<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\Debt;
use App\Models\User;

/**
 * Dívida herda a visibilidade da conta de pagamento.
 */
class DebtPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->current_household_id !== null;
    }

    public function view(User $user, Debt $debt): bool
    {
        return $this->canSeeAccount($user, $debt);
    }

    public function create(User $user): bool
    {
        return $user->current_household_id !== null;
    }

    public function update(User $user, Debt $debt): bool
    {
        return $this->canSeeAccount($user, $debt);
    }

    public function delete(User $user, Debt $debt): bool
    {
        return $this->canSeeAccount($user, $debt);
    }

    private function canSeeAccount(User $user, Debt $debt): bool
    {
        $account = Account::withoutGlobalScopes()->find($debt->payment_account_id);

        return $account !== null && $account->isVisibleTo($user);
    }
}
