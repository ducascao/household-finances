<?php

namespace App\Policies;

use App\Models\Goal;
use App\Models\User;

/**
 * Meta pessoal: só o dono. Compartilhada: todo o lar.
 */
class GoalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->current_household_id !== null;
    }

    public function view(User $user, Goal $goal): bool
    {
        return $goal->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return $user->current_household_id !== null;
    }

    public function update(User $user, Goal $goal): bool
    {
        return $goal->isVisibleTo($user);
    }

    public function delete(User $user, Goal $goal): bool
    {
        return $goal->owner_id === $user->id;
    }
}
