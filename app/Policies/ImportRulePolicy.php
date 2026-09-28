<?php

namespace App\Policies;

use App\Models\ImportRule;
use App\Models\User;

/**
 * Regras são do lar todo.
 */
class ImportRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->current_household_id !== null;
    }

    public function view(User $user, ImportRule $rule): bool
    {
        return $rule->household_id === $user->current_household_id;
    }

    public function create(User $user): bool
    {
        return $user->current_household_id !== null;
    }

    public function update(User $user, ImportRule $rule): bool
    {
        return $rule->household_id === $user->current_household_id;
    }

    public function delete(User $user, ImportRule $rule): bool
    {
        return $rule->household_id === $user->current_household_id;
    }
}
