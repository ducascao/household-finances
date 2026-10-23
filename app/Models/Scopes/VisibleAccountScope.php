<?php

namespace App\Models\Scopes;

use App\Enums\AccountVisibility;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Com usuário logado, só retorna as contas compartilhadas e as pessoais dele.
 * Modelos que herdam a visibilidade da conta (lançamentos) filtram via whereHas('account'),
 * que aplica este mesmo escopo.
 *
 * Dentro de onlyShared(), as pessoais do usuário também ficam de fora: usado para montar relatórios
 * "só o compartilhado" (ex.: e-mail enviado a outra pessoa) reaproveitando os cálculos das telas.
 *
 * @implements Scope<Model>
 */
class VisibleAccountScope implements Scope
{
    private static bool $onlyShared = false;

    /**
     * Executa o callback vendo só as contas (e bens, metas…) compartilhados do lar.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function onlyShared(Closure $callback): mixed
    {
        $previous = self::$onlyShared;
        self::$onlyShared = true;

        try {
            return $callback();
        } finally {
            self::$onlyShared = $previous;
        }
    }

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
        if (self::$onlyShared) {
            $builder->where($model->qualifyColumn('visibility'), AccountVisibility::Shared->value);

            return;
        }

        $builder->where(function (Builder $query) use ($model, $user): void {
            $query->where($model->qualifyColumn('visibility'), AccountVisibility::Shared->value)
                ->orWhere($model->qualifyColumn('owner_id'), $user->id);
        });
    }
}
