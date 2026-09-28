<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;

/**
 * Conta compartilhada: todos do lar veem e editam. Conta pessoal: só o dono.
 */
class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->current_household_id !== null;
    }

    public function view(User $user, Account $account): bool
    {
        return $account->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return $user->current_household_id !== null;
    }

    public function update(User $user, Account $account): bool
    {
        return $account->isVisibleTo($user);
    }

    /**
     * Visibilidade e dono só podem ser alterados pelo dono da conta.
     */
    public function changeVisibility(User $user, Account $account): bool
    {
        return $account->owner_id === $user->id;
    }

    /**
     * Conta com lançamentos não é excluída: arquive.
     */
    public function delete(User $user, Account $account): bool
    {
        return $account->owner_id === $user->id
            && ! $account->transactions()->withoutGlobalScopes()->exists();
    }
}
