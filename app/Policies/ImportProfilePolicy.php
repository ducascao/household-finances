<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\ImportProfile;
use App\Models\User;

class ImportProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->current_household_id !== null;
    }

    public function view(User $user, ImportProfile $profile): bool
    {
        return $this->canSeeAccount($user, $profile);
    }

    public function create(User $user): bool
    {
        return $user->current_household_id !== null;
    }

    public function update(User $user, ImportProfile $profile): bool
    {
        return $this->canSeeAccount($user, $profile);
    }

    public function delete(User $user, ImportProfile $profile): bool
    {
        return $this->canSeeAccount($user, $profile);
    }

    private function canSeeAccount(User $user, ImportProfile $profile): bool
    {
        $account = Account::withoutGlobalScopes()->find($profile->account_id);

        return $account !== null && $account->isVisibleTo($user);
    }
}
