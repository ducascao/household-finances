<?php

namespace App\Domain\Recurrences;

use App\Models\Recurrence;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateRecurrence
{
    public function __construct(
        private readonly GenerateOccurrences $generateOccurrences,
    ) {}

    /**
     * Cria a recorrência e já gera os previstos até o horizonte.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(User $actor, array $data): Recurrence
    {
        $attributes = RecurrenceData::resolve($actor, $data);

        return DB::transaction(function () use ($attributes): Recurrence {
            $recurrence = new Recurrence;
            $recurrence->household_id = $attributes['household_id'];
            unset($attributes['household_id']);
            $recurrence->fill($attributes);
            $recurrence->next_date = RecurrenceSchedule::first($recurrence);
            $recurrence->save();

            $this->generateOccurrences->execute($recurrence);

            return $recurrence;
        });
    }
}
