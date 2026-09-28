<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\Recurrence;
use App\Models\User;

/**
 * Recorrência herda a visibilidade da conta.
 */
class RecurrencePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->current_household_id !== null;
    }

    public function view(User $user, Recurrence $recurrence): bool
    {
        return $this->canSeeAccount($user, $recurrence);
    }

    public function create(User $user): bool
    {
        return $user->current_household_id !== null;
    }

    public function update(User $user, Recurrence $recurrence): bool
    {
        return $this->canSeeAccount($user, $recurrence);
    }

    public function delete(User $user, Recurrence $recurrence): bool
    {
        return $this->canSeeAccount($user, $recurrence);
    }

    private function canSeeAccount(User $user, Recurrence $recurrence): bool
    {
        $account = Account::withoutGlobalScopes()->find($recurrence->account_id);

        return $account !== null && $account->isVisibleTo($user);
    }
}
