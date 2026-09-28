<?php

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Restringe as consultas ao lar atual do usuário logado.
 *
 * Sem usuário logado (console, jobs) o escopo não filtra: nesses contextos
 * o household_id deve ser informado explicitamente.
 *
 * @implements Scope<Model>
 */
class HouseholdScope implements Scope
{
    public static function currentHouseholdId(): ?int
    {
        $user = Auth::user();

        return $user instanceof User ? $user->current_household_id : null;
    }

    public function apply(Builder $builder, Model $model): void
    {
        $householdId = self::currentHouseholdId();

        if ($householdId !== null) {
            $builder->where($model->qualifyColumn('household_id'), $householdId);
        }
    }
}
