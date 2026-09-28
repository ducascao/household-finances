<?php

namespace App\Domain\Transactions;

use App\Enums\CategoryType;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Valida os dados de um lançamento e aplica as regras de sinal, moeda, competência e status.
 */
class TransactionData
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{household_id: int, account_id: int, category_id: int, amount: int, status: TransactionStatus, currency: string, date: Carbon, due_date: Carbon|null, competence_date: Carbon, description: string, paid_by: int, notes: string|null, tags: list<string>}
     */
    public static function resolve(User $actor, array $data, ?Account $currentAccount = null): array
    {
        $data['status'] = ($data['status'] ?? null) instanceof TransactionStatus ? $data['status']->value : ($data['status'] ?? TransactionStatus::Paid->value);

        /** @var array{account_id: int, category_id: int, amount: int, status: string, date?: string|null, due_date?: string|null, competence_date?: string|null, description: string, paid_by?: int|null, notes?: string|null, tags?: list<string>|null} $validated */
        $validated = Validator::make($data, [
            'account_id' => ['required', 'integer'],
            'category_id' => ['required', 'integer'],
            'amount' => ['required', 'integer', 'not_in:0'],
            'status' => ['required', Rule::enum(TransactionStatus::class)],
            'date' => ['required_unless:status,scheduled', 'nullable', 'date'],
            'due_date' => ['required_if:status,scheduled', 'nullable', 'date'],
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
            'status' => 'status',
            'date' => 'data',
            'due_date' => 'vencimento',
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
        $status = TransactionStatus::from($validated['status']);
        $dueDate = ($validated['due_date'] ?? null) !== null ? Carbon::parse($validated['due_date'])->startOfDay() : null;

        // Previsto: a data de caixa acompanha o vencimento até ser pago.
        $date = $status === TransactionStatus::Scheduled && $dueDate !== null
            ? $dueDate->copy()
            : Carbon::parse((string) ($validated['date'] ?? null))->startOfDay();
        $competence = ($validated['competence_date'] ?? null) !== null ? Carbon::parse($validated['competence_date']) : $date;

        return [
            'household_id' => $account->household_id,
            'account_id' => $account->id,
            'category_id' => $category->id,
            'amount' => $category->type === CategoryType::Expense ? -$absolute : $absolute,
            'status' => $status,
            'currency' => $account->currency,
            'date' => $date,
            'due_date' => $dueDate,
            'competence_date' => $competence->copy()->startOfMonth()->startOfDay(),
            'description' => trim($validated['description']),
            'paid_by' => (int) $paidBy,
            'notes' => ($validated['notes'] ?? null) ?: null,
            'tags' => array_values(array_unique(array_map('trim', $validated['tags'] ?? []))),
        ];
    }
}
