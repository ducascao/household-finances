<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

/**
 * Categorias são do lar inteiro: qualquer membro gerencia.
 */
class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->current_household_id !== null;
    }

    public function view(User $user, Category $category): bool
    {
        return $category->household_id === $user->current_household_id;
    }

    public function create(User $user): bool
    {
        return $user->current_household_id !== null;
    }

    public function update(User $user, Category $category): bool
    {
        return $category->household_id === $user->current_household_id;
    }

    public function delete(User $user, Category $category): bool
    {
        return $category->household_id === $user->current_household_id;
    }
}
