<?php

namespace App\Domain\Recurrences;

use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Recurrence;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class UpdateRecurrence
{
    public function __construct(
        private readonly GenerateOccurrences $generateOccurrences,
    ) {}

    /**
     * Altera a recorrência. Com $applyToGenerated, os previstos já gerados de hoje em diante e ainda
     * não pagos são refeitos com as regras novas; sem ele, valem só para as próximas ocorrências.
     * Pagos e atrasados nunca são alterados.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(User $actor, Recurrence $recurrence, array $data, bool $applyToGenerated): Recurrence
    {
        $currentAccount = Account::withoutGlobalScopes()->find($recurrence->account_id);
        $attributes = RecurrenceData::resolve($actor, $data, $currentAccount);

        return DB::transaction(function () use ($recurrence, $attributes, $applyToGenerated): Recurrence {
            if ($applyToGenerated) {
                $this->generated($recurrence)
                    ->where('status', TransactionStatus::Scheduled->value)
                    ->whereDate('occurrence_date', '>=', today())
                    ->delete();
            }

            unset($attributes['household_id']);
            $recurrence->fill($attributes);

            // Continua no período seguinte à última ocorrência que ficou gerada (ou no início).
            $lastGenerated = $this->generated($recurrence)->max('occurrence_date');
            $recurrence->next_date = $lastGenerated !== null
                ? RecurrenceSchedule::nextPeriodAfter($recurrence, Carbon::parse($lastGenerated))
                : RecurrenceSchedule::first($recurrence);
            $recurrence->save();

            $this->generateOccurrences->execute($recurrence);

            return $recurrence;
        });
    }

    /**
     * @return Builder<Transaction>
     */
    private function generated(Recurrence $recurrence): Builder
    {
        return Transaction::withoutGlobalScopes()->where('recurrence_id', $recurrence->id);
    }
}
