<?php

namespace App\Models\Scopes;

use App\Enums\AccountVisibility;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Com usuário logado, só retorna as contas compartilhadas e as pessoais dele.
 * Modelos que herdam a visibilidade da conta (lançamentos) filtram via whereHas('account'),
 * que aplica este mesmo escopo.
 *
 * @implements Scope<Model>
 */
class VisibleAccountScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if ($user instanceof User) {
            self::constrain($builder, $model, $user);
        }
    }

    /**
     * @param  Builder<covariant Model>  $builder
     */
    public static function constrain(Builder $builder, Model $model, User $user): void
    {
        $builder->where(function (Builder $query) use ($model, $user): void {
            $query->where($model->qualifyColumn('visibility'), AccountVisibility::Shared->value)
                ->orWhere($model->qualifyColumn('owner_id'), $user->id);
        });
    }
}
