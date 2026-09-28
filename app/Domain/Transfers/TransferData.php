<?php

namespace App\Domain\Transfers;

use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Valida os dados de uma transferência entre duas contas do mesmo lar e da mesma moeda.
 */
class TransferData
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $currentAccountIds  contas atuais da transferência (podem estar arquivadas)
     * @return array{from: Account, to: Account, amount: int, status: TransactionStatus, date: Carbon, due_date: Carbon|null, competence_date: Carbon, description: string, paid_by: int, notes: string|null}
     */
    public static function resolve(User $actor, array $data, array $currentAccountIds = []): array
    {
        $data['status'] = ($data['status'] ?? null) instanceof TransactionStatus ? $data['status']->value : ($data['status'] ?? TransactionStatus::Paid->value);
        $data['description'] = ($data['description'] ?? null) ?: 'Transferência';

        /** @var array{from_account_id: int, to_account_id: int, amount: int, status: string, date?: string|null, due_date?: string|null, description: string, notes?: string|null} $validated */
        $validated = Validator::make($data, [
            'from_account_id' => ['required', 'integer'],
            'to_account_id' => ['required', 'integer', 'different:from_account_id'],
            'amount' => ['required', 'integer', 'not_in:0'],
            'status' => ['required', Rule::enum(TransactionStatus::class)],
            'date' => ['required_unless:status,scheduled', 'nullable', 'date'],
            'due_date' => ['required_if:status,scheduled', 'nullable', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ], [
            'to_account_id.different' => 'A conta de destino precisa ser diferente da de origem.',
        ], [
            'from_account_id' => 'conta de origem',
            'to_account_id' => 'conta de destino',
            'amount' => 'valor',
            'date' => 'data',
            'due_date' => 'vencimento',
            'description' => 'descrição',
        ])->validate();

        $from = self::account($actor, (int) $validated['from_account_id'], 'from_account_id', $currentAccountIds);
        $to = self::account($actor, (int) $validated['to_account_id'], 'to_account_id', $currentAccountIds);

        if ($from->household_id !== $to->household_id) {
            throw ValidationException::withMessages(['to_account_id' => 'Conta de destino não encontrada.']);
        }

        if ($from->currency !== $to->currency) {
            throw ValidationException::withMessages([
                'to_account_id' => 'Transferência só entre contas da mesma moeda.',
            ]);
        }

        $status = TransactionStatus::from($validated['status']);
        $dueDate = ($validated['due_date'] ?? null) !== null ? Carbon::parse($validated['due_date'])->startOfDay() : null;
        $date = $status === TransactionStatus::Scheduled && $dueDate !== null
            ? $dueDate->copy()
            : Carbon::parse((string) ($validated['date'] ?? null))->startOfDay();

        return [
            'from' => $from,
            'to' => $to,
            'amount' => abs((int) $validated['amount']),
            'status' => $status,
            'date' => $date,
            'due_date' => $dueDate,
            'competence_date' => $date->copy()->startOfMonth(),
            'description' => trim($validated['description']),
            'paid_by' => $actor->id,
            'notes' => ($validated['notes'] ?? null) ?: null,
        ];
    }

    /**
     * @param  list<int>  $currentAccountIds
     */
    private static function account(User $actor, int $id, string $field, array $currentAccountIds): Account
    {
        $account = Account::withoutGlobalScopes()->find($id);

        if ($account === null || ! $account->isVisibleTo($actor)) {
            throw ValidationException::withMessages([$field => 'Conta não encontrada.']);
        }

        if ($account->isArchived() && ! in_array($account->id, $currentAccountIds, true)) {
            throw ValidationException::withMessages([$field => 'A conta está arquivada.']);
        }

        return $account;
    }
}
