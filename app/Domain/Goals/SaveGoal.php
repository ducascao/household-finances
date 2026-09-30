<?php

namespace App\Domain\Goals;

use App\Enums\AccountVisibility;
use App\Models\Account;
use App\Models\Asset;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Cria ou altera a meta e seus vínculos. Meta compartilhada só vincula contas compartilhadas (e ativos delas),
 * para não expor saldo pessoal; meta pessoal vincula as contas do dono e as compartilhadas.
 */
class SaveGoal
{
    /**
     * @param  array<string, mixed>  $data  name, target, start_date?, deadline, visibility, notes, account_ids[], asset_ids[]
     */
    public function execute(User $actor, array $data, ?Goal $goal = null): Goal
    {
        $data['visibility'] = ($data['visibility'] ?? null) instanceof AccountVisibility ? $data['visibility']->value : ($data['visibility'] ?? null);

        /** @var array{name: string, target: int, start_date?: string|null, deadline: string, visibility: string, notes?: string|null, account_ids?: list<int>|null, asset_ids?: list<int>|null} $validated */
        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'target' => ['required', 'integer', 'min:1'],
            'start_date' => ['nullable', 'date'],
            'deadline' => ['required', 'date', 'after_or_equal:today'],
            'visibility' => ['required', Rule::enum(AccountVisibility::class)],
            'notes' => ['nullable', 'string'],
            'account_ids' => ['nullable', 'array'],
            'account_ids.*' => ['integer'],
            'asset_ids' => ['nullable', 'array'],
            'asset_ids.*' => ['integer'],
        ], ['deadline.after_or_equal' => 'O prazo não pode estar no passado.'], [
            'name' => 'nome', 'target' => 'valor-alvo', 'deadline' => 'prazo', 'visibility' => 'visibilidade',
        ])->validate();

        if ($goal !== null && $goal->owner_id !== $actor->id && $validated['visibility'] !== $goal->visibility->value) {
            throw ValidationException::withMessages(['visibility' => 'Só o dono da meta pode alterar a visibilidade.']);
        }

        $shared = $validated['visibility'] === AccountVisibility::Shared->value;
        $owner = $goal !== null ? $goal->owner_id : $actor->id;
        $accountIds = array_map('intval', $validated['account_ids'] ?? []);
        $assetIds = array_map('intval', $validated['asset_ids'] ?? []);

        if ($accountIds === [] && $assetIds === []) {
            throw ValidationException::withMessages(['account_ids' => 'Vincule ao menos uma conta ou investimento: o progresso vem do saldo deles.']);
        }

        $accountOk = function (?Account $account) use ($actor, $shared, $owner): bool {
            return $account !== null
                && $account->household_id === $actor->current_household_id
                && ($shared ? $account->visibility === AccountVisibility::Shared : ($account->visibility === AccountVisibility::Shared || $account->owner_id === $owner));
        };

        foreach ($accountIds as $id) {
            if (! $accountOk(Account::withoutGlobalScopes()->find($id))) {
                throw ValidationException::withMessages(['account_ids' => $shared
                    ? 'Meta compartilhada só pode usar contas compartilhadas.'
                    : 'Conta não encontrada.']);
            }
        }

        foreach ($assetIds as $id) {
            $asset = Asset::withoutGlobalScopes()->find($id);

            if ($asset === null || ! $accountOk(Account::withoutGlobalScopes()->find($asset->account_id))) {
                throw ValidationException::withMessages(['asset_ids' => $shared
                    ? 'Meta compartilhada só pode usar investimentos de contas compartilhadas.'
                    : 'Investimento não encontrado.']);
            }
        }

        return DB::transaction(function () use ($actor, $goal, $validated, $owner, $accountIds, $assetIds): Goal {
            $goal ??= new Goal;
            $goal->household_id = (int) $actor->current_household_id;
            $goal->fill([
                'owner_id' => $owner,
                'name' => $validated['name'],
                'target' => (int) $validated['target'],
                'start_date' => $goal->start_date ?? Carbon::parse($validated['start_date'] ?? today()),
                'deadline' => Carbon::parse($validated['deadline']),
                'visibility' => $validated['visibility'],
                'notes' => ($validated['notes'] ?? null) ?: null,
            ])->save();

            $goal->accounts()->sync($accountIds);
            $goal->assets()->sync($assetIds);

            return $goal;
        });
    }

    public function archive(User $actor, Goal $goal, bool $archive = true): void
    {
        if (! $goal->isVisibleTo($actor)) {
            throw ValidationException::withMessages(['goal' => 'Sem acesso a esta meta.']);
        }

        $goal->archived_at = $archive ? now() : null;
        $goal->save();
    }
}
