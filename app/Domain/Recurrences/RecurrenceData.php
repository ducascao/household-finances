<?php

namespace App\Domain\Recurrences;

use App\Domain\Transactions\TransactionData;
use App\Enums\RecurrenceFrequency;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Valida uma recorrência. Conta, categoria, sinal do valor, pago por e tags seguem as mesmas regras do lançamento.
 */
class RecurrenceData
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{household_id: int, account_id: int, category_id: int, amount: int, currency: string, amount_is_estimate: bool, description: string, paid_by: int, notes: string|null, tags: list<string>, frequency: RecurrenceFrequency, interval_months: int|null, day_of_month: int|null, start_date: Carbon, end_date: Carbon|null}
     */
    public static function resolve(User $actor, array $data, ?Account $currentAccount = null): array
    {
        $data['frequency'] = ($data['frequency'] ?? null) instanceof RecurrenceFrequency ? $data['frequency']->value : ($data['frequency'] ?? null);

        /** @var array{frequency: string, interval_months?: int|null, day_of_month?: int|null, start_date: string, end_date?: string|null, amount_is_estimate?: bool|null} $schedule */
        $schedule = Validator::make($data, [
            'frequency' => ['required', Rule::enum(RecurrenceFrequency::class)],
            'interval_months' => ['required_if:frequency,every_n_months', 'nullable', 'integer', 'between:2,60'],
            'day_of_month' => ['nullable', 'integer', 'between:1,31'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'amount_is_estimate' => ['nullable', 'boolean'],
        ], attributes: [
            'frequency' => 'frequência',
            'interval_months' => 'intervalo em meses',
            'day_of_month' => 'dia',
            'start_date' => 'início',
            'end_date' => 'fim',
        ])->validate();

        $frequency = RecurrenceFrequency::from($schedule['frequency']);
        $start = Carbon::parse($schedule['start_date'])->startOfDay();

        $entry = TransactionData::resolve($actor, [
            'account_id' => $data['account_id'] ?? null,
            'category_id' => $data['category_id'] ?? null,
            'amount' => $data['amount'] ?? null,
            'date' => $start->toDateString(),
            'description' => $data['description'] ?? null,
            'paid_by' => $data['paid_by'] ?? null,
            'notes' => $data['notes'] ?? null,
            'tags' => $data['tags'] ?? null,
        ], $currentAccount);

        return [
            'household_id' => $entry['household_id'],
            'account_id' => $entry['account_id'],
            'category_id' => $entry['category_id'],
            'amount' => $entry['amount'],
            'currency' => $entry['currency'],
            'amount_is_estimate' => (bool) ($schedule['amount_is_estimate'] ?? false),
            'description' => $entry['description'],
            'paid_by' => $entry['paid_by'],
            'notes' => $entry['notes'],
            'tags' => $entry['tags'],
            'frequency' => $frequency,
            'interval_months' => $frequency === RecurrenceFrequency::EveryNMonths ? (int) $schedule['interval_months'] : null,
            'day_of_month' => $frequency->usesDayOfMonth() ? (int) ($schedule['day_of_month'] ?? $start->day) : null,
            'start_date' => $start,
            'end_date' => ($schedule['end_date'] ?? null) !== null ? Carbon::parse($schedule['end_date'])->startOfDay() : null,
        ];
    }
}
