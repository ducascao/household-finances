<?php

namespace App\Domain\Transactions;

use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Valida os dados de um lançamento e aplica as regras de sinal, moeda e competência.
 */
class TransactionData
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{household_id: int, account_id: int, category_id: int, amount: int, currency: string, date: Carbon, competence_date: Carbon, description: string, paid_by: int, notes: string|null, tags: list<string>}
     */
    public static function resolve(User $actor, array $data, ?Account $currentAccount = null): array
    {
        /** @var array{account_id: int, category_id: int, amount: int, date: string, competence_date?: string|null, description: string, paid_by?: int|null, notes?: string|null, tags?: list<string>|null} $validated */
        $validated = Validator::make($data, [
            'account_id' => ['required', 'integer'],
            'category_id' => ['required', 'integer'],
            'amount' => ['required', 'integer', 'not_in:0'],
            'date' => ['required', 'date'],
            'competence_date' => ['nullable', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'paid_by' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
        ], attributes: [
            'account_id' => 'conta',
            'category_id' => 'categoria',
            'amount' => 'valor',
            'date' => 'data',
            'competence_date' => 'competência',
            'description' => 'descrição',
            'paid_by' => 'pago por',
            'notes' => 'observações',
            'tags' => 'tags',
        ])->validate();

        $account = Account::withoutGlobalScopes()->find($validated['account_id']);

        if ($account === null || ! $account->isVisibleTo($actor)) {
            throw ValidationException::withMessages(['account_id' => 'Conta não encontrada.']);
        }

        if ($account->isArchived() && $account->id !== $currentAccount?->id) {
            throw ValidationException::withMessages(['account_id' => 'A conta está arquivada.']);
        }

        $category = Category::withoutGlobalScopes()
            ->where('household_id', $account->household_id)
            ->find($validated['category_id']);

        if ($category === null) {
            throw ValidationException::withMessages(['category_id' => 'Categoria não encontrada.']);
        }

        $paidBy = $validated['paid_by'] ?? $actor->id;

        $isMember = User::query()
            ->whereKey($paidBy)
            ->whereHas('households', fn ($query) => $query->whereKey($account->household_id))
            ->exists();

        if (! $isMember) {
            throw ValidationException::withMessages(['paid_by' => 'Quem pagou precisa ser membro do lar.']);
        }

        $absolute = abs((int) $validated['amount']);
        $date = Carbon::parse($validated['date'])->startOfDay();
        $competence = ($validated['competence_date'] ?? null) !== null ? Carbon::parse($validated['competence_date']) : $date;

        return [
            'household_id' => $account->household_id,
            'account_id' => $account->id,
            'category_id' => $category->id,
            'amount' => $category->type === CategoryType::Expense ? -$absolute : $absolute,
            'currency' => $account->currency,
            'date' => $date,
            'competence_date' => $competence->copy()->startOfMonth()->startOfDay(),
            'description' => trim($validated['description']),
            'paid_by' => (int) $paidBy,
            'notes' => ($validated['notes'] ?? null) ?: null,
            'tags' => array_values(array_unique(array_map('trim', $validated['tags'] ?? []))),
        ];
    }
}
