<?php

namespace App\Domain\Goods;

use App\Enums\AccountVisibility;
use App\Enums\GoodType;
use App\Models\Account;
use App\Models\Debt;
use App\Models\Good;
use App\Models\GoodValuation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Cadastro de bens e avaliações. Comprar ou vender o bem não gera lançamento: o dinheiro já aparece
 * nas contas e na dívida. Bem compartilhado só vincula dívida paga por conta compartilhada.
 */
class ManageGoods
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function save(User $actor, array $data, ?Good $good = null): Good
    {
        foreach (['type' => GoodType::class, 'visibility' => AccountVisibility::class] as $key => $enum) {
            $data[$key] = ($data[$key] ?? null) instanceof $enum ? $data[$key]->value : ($data[$key] ?? null);
        }

        /** @var array<string, mixed> $validated */
        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(GoodType::class)],
            'visibility' => ['required', Rule::enum(AccountVisibility::class)],
            'acquisition_date' => ['required', 'date', 'before_or_equal:today'],
            'acquisition_value' => ['required', 'integer', 'min:0'],
            'sale_date' => ['nullable', 'date', 'after_or_equal:acquisition_date', 'before_or_equal:today'],
            'sale_value' => ['nullable', 'required_with:sale_date', 'integer', 'min:0'],
            'debt_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
        ], [
            'acquisition_date.before_or_equal' => 'A data de aquisição não pode ser futura.',
            'sale_date.after_or_equal' => 'A venda não pode ser antes da aquisição.',
        ], [
            'name' => 'nome', 'type' => 'tipo', 'visibility' => 'visibilidade', 'acquisition_date' => 'data de aquisição',
            'acquisition_value' => 'valor de aquisição', 'sale_date' => 'data de venda', 'sale_value' => 'valor de venda', 'debt_id' => 'dívida',
        ])->validate();

        if ($good !== null && $good->owner_id !== $actor->id && $validated['visibility'] !== $good->visibility->value) {
            throw ValidationException::withMessages(['visibility' => 'Só o dono do bem pode alterar a visibilidade.']);
        }

        $owner = $good !== null ? $good->owner_id : $actor->id;
        $debtId = ($validated['debt_id'] ?? null) ?: null;

        if ($debtId !== null) {
            $this->ensureDebt($actor, (int) $debtId, $validated['visibility'] === AccountVisibility::Shared->value, $owner);
        }

        $good ??= new Good;
        $good->household_id = (int) $actor->current_household_id;
        $good->fill([
            ...$validated,
            'owner_id' => $owner,
            'debt_id' => $debtId,
            'sale_date' => ($validated['sale_date'] ?? null) ?: null,
            'sale_value' => ($validated['sale_date'] ?? null) ? (int) $validated['sale_value'] : null,
            'notes' => ($validated['notes'] ?? null) ?: null,
        ])->save();

        return $good;
    }

    public function delete(User $actor, Good $good): void
    {
        $this->ensureAccess($actor, $good);
        $good->delete();
    }

    public function value(User $actor, Good $good, Carbon $date, ?int $value): GoodValuation
    {
        $this->ensureAccess($actor, $good);

        if ($value === null || $value < 0) {
            throw ValidationException::withMessages(['value' => 'Informe o valor (zero ou mais).']);
        }

        if ($date->isFuture() || $date->lt($good->acquisition_date)) {
            throw ValidationException::withMessages(['date' => 'A data deve estar entre a aquisição e hoje.']);
        }

        $valuation = GoodValuation::withoutGlobalScopes()->where('good_id', $good->id)->whereDate('date', $date)->first()
            ?? new GoodValuation(['good_id' => $good->id, 'date' => $date->copy()->startOfDay()]);
        $valuation->household_id = $good->household_id;
        $valuation->value = $value;
        $valuation->save();

        return $valuation;
    }

    public function deleteValuation(User $actor, GoodValuation $valuation): void
    {
        $this->ensureAccess($actor, Good::withoutGlobalScopes()->findOrFail($valuation->good_id));
        $valuation->delete();
    }

    private function ensureDebt(User $actor, int $debtId, bool $shared, int $owner): void
    {
        $debt = Debt::withoutGlobalScopes()->where('household_id', $actor->current_household_id)->find($debtId);
        $account = $debt !== null ? Account::withoutGlobalScopes()->find($debt->payment_account_id) : null;

        $ok = $account !== null && ($shared
            ? $account->visibility === AccountVisibility::Shared
            : ($account->visibility === AccountVisibility::Shared || $account->owner_id === $owner));

        if (! $ok) {
            throw ValidationException::withMessages(['debt_id' => $shared
                ? 'Bem compartilhado só pode vincular dívida paga por conta compartilhada.'
                : 'Dívida não encontrada.']);
        }
    }

    private function ensureAccess(User $actor, Good $good): void
    {
        if (! $good->isVisibleTo($actor)) {
            throw ValidationException::withMessages(['good' => 'Sem acesso a este bem.']);
        }
    }
}
