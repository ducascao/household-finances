<?php

namespace App\Policies;

use App\Models\Good;
use App\Models\User;

/**
 * Bem pessoal: só o dono. Compartilhado: todo o lar.
 */
class GoodPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->current_household_id !== null;
    }

    public function view(User $user, Good $good): bool
    {
        return $good->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return $user->current_household_id !== null;
    }

    public function update(User $user, Good $good): bool
    {
        return $good->isVisibleTo($user);
    }

    public function delete(User $user, Good $good): bool
    {
        return $good->isVisibleTo($user);
    }
}
