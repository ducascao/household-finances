<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\Asset;
use App\Models\User;

/**
 * Ativo herda a visibilidade da conta da corretora.
 */
class AssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->current_household_id !== null;
    }

    public function view(User $user, Asset $asset): bool
    {
        return $this->canSeeAccount($user, $asset);
    }

    public function create(User $user): bool
    {
        return $user->current_household_id !== null;
    }

    public function update(User $user, Asset $asset): bool
    {
        return $this->canSeeAccount($user, $asset);
    }

    public function delete(User $user, Asset $asset): bool
    {
        return $this->canSeeAccount($user, $asset) && ! $asset->operations()->withoutGlobalScopes()->exists();
    }

    private function canSeeAccount(User $user, Asset $asset): bool
    {
        $account = Account::withoutGlobalScopes()->find($asset->account_id);

        return $account !== null && $account->isVisibleTo($user);
    }
}
