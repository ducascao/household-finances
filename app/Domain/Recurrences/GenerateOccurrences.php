<?php

namespace App\Domain\Recurrences;

use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Recurrence;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Gera os lançamentos previstos de uma recorrência até o horizonte (padrão: hoje + 60 dias).
 *
 * O ponteiro next_date avança a cada ocorrência gerada, então rodar de novo não duplica e um
 * lançamento excluído à mão não volta. O índice único (recurrence_id, occurrence_date) é a garantia final.
 * Roda sem usuário logado: lê sem escopos e grava o household_id da recorrência.
 */
class GenerateOccurrences
{
    public const HORIZON_DAYS = 60;

    /**
     * @return int quantidade de lançamentos criados
     */
    public function execute(Recurrence $recurrence, ?Carbon $until = null): int
    {
        $until = ($until ?? today()->addDays(self::HORIZON_DAYS))->copy()->startOfDay();

        return DB::transaction(function () use ($recurrence, $until): int {
            $locked = Recurrence::withoutGlobalScopes()->lockForUpdate()->findOrFail($recurrence->id);
            $account = Account::withoutGlobalScopes()->find($locked->account_id);

            if ($account === null || $account->isArchived()) {
                return 0;
            }

            $created = 0;
            $next = $locked->next_date->copy();

            while ($next->lte($until) && ($locked->end_date === null || $next->lte($locked->end_date))) {
                $created += $this->createOccurrence($locked, $next) ? 1 : 0;
                $next = RecurrenceSchedule::after($locked, $next);
            }

            $locked->next_date = $next;
            $locked->save();
            $recurrence->setRawAttributes($locked->getAttributes(), true);

            return $created;
        });
    }

    /**
     * Gera para todas as recorrências de todos os lares (job diário).
     *
     * @return int quantidade de lançamentos criados
     */
    public function executeAll(?Carbon $until = null): int
    {
        $until ??= today()->addDays(self::HORIZON_DAYS);
        $created = 0;

        Recurrence::withoutGlobalScopes()
            ->whereDate('next_date', '<=', $until)
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereColumn('next_date', '<=', 'end_date'))
            ->orderBy('id')
            ->each(function (Recurrence $recurrence) use ($until, &$created): void {
                $created += $this->execute($recurrence, $until);
            });

        return $created;
    }

    private function createOccurrence(Recurrence $recurrence, Carbon $date): bool
    {
        $exists = Transaction::withoutGlobalScopes()
            ->where('recurrence_id', $recurrence->id)
            ->whereDate('occurrence_date', $date)
            ->exists();

        if ($exists) {
            return false;
        }

        $transaction = new Transaction;
        $transaction->household_id = $recurrence->household_id;
        $transaction->fill([
            'recurrence_id' => $recurrence->id,
            'occurrence_date' => $date,
            'account_id' => $recurrence->account_id,
            'category_id' => $recurrence->category_id,
            'amount' => $recurrence->amount,
            'currency' => $recurrence->currency,
            'status' => TransactionStatus::Scheduled,
            'date' => $date,
            'due_date' => $date,
            'competence_date' => $date->copy()->startOfMonth(),
            'description' => $recurrence->description,
            'paid_by' => $recurrence->paid_by,
            'notes' => $recurrence->notes,
            'tags' => $recurrence->tags,
        ])->save();

        return true;
    }
}
