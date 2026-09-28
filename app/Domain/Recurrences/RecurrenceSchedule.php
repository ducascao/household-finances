<?php

namespace App\Domain\Recurrences;

use App\Enums\RecurrenceFrequency;
use App\Models\Recurrence;
use Illuminate\Support\Carbon;

/**
 * Datas das ocorrências de uma recorrência.
 *
 * Mensal, anual e a cada N meses usam o dia configurado (day_of_month); em meses mais curtos
 * a ocorrência cai no último dia do mês, e o dia configurado volta a valer no mês seguinte.
 * As ocorrências ficam alinhadas ao mês da data de início. Semanal: a cada 7 dias a partir do início.
 */
class RecurrenceSchedule
{
    /**
     * Primeira ocorrência a partir da data de início (inclusive).
     */
    public static function first(Recurrence $recurrence): Carbon
    {
        $start = $recurrence->start_date->copy()->startOfDay();

        if ($recurrence->frequency === RecurrenceFrequency::Weekly) {
            return $start;
        }

        $candidate = self::inMonth($start, self::dayOfMonth($recurrence));

        return $candidate->lt($start) ? self::after($recurrence, $candidate) : $candidate;
    }

    /**
     * Ocorrência seguinte a uma ocorrência.
     */
    public static function after(Recurrence $recurrence, Carbon $occurrence): Carbon
    {
        if ($recurrence->frequency === RecurrenceFrequency::Weekly) {
            return $occurrence->copy()->startOfDay()->addDays(7);
        }

        $month = $occurrence->copy()->startOfMonth()->addMonthsNoOverflow(self::monthStep($recurrence));

        return self::inMonth($month, self::dayOfMonth($recurrence));
    }

    /**
     * Primeira ocorrência na data informada ou depois dela.
     */
    public static function firstOnOrAfter(Recurrence $recurrence, Carbon $date): Carbon
    {
        $occurrence = self::first($recurrence);

        while ($occurrence->lt($date->copy()->startOfDay())) {
            $occurrence = self::after($recurrence, $occurrence);
        }

        return $occurrence;
    }

    /**
     * Frequência por extenso, ex.: "Mensal, dia 10", "A cada 3 meses, dia 5", "Anual, 29/02".
     */
    public static function describe(Recurrence $recurrence): string
    {
        $day = self::dayOfMonth($recurrence);

        return match ($recurrence->frequency) {
            RecurrenceFrequency::Weekly => 'Semanal, '.$recurrence->start_date->locale('pt_BR')->dayName,
            RecurrenceFrequency::Monthly => "Mensal, dia {$day}",
            RecurrenceFrequency::EveryNMonths => "A cada {$recurrence->interval_months} meses, dia {$day}",
            RecurrenceFrequency::Yearly => 'Anual, '.sprintf('%02d/%02d', $day, $recurrence->start_date->month),
        };
    }

    private static function monthStep(Recurrence $recurrence): int
    {
        return match ($recurrence->frequency) {
            RecurrenceFrequency::Monthly => 1,
            RecurrenceFrequency::EveryNMonths => max(1, (int) $recurrence->interval_months),
            RecurrenceFrequency::Yearly => 12,
            RecurrenceFrequency::Weekly => 0,
        };
    }

    private static function dayOfMonth(Recurrence $recurrence): int
    {
        return $recurrence->day_of_month ?? $recurrence->start_date->day;
    }

    private static function inMonth(Carbon $month, int $day): Carbon
    {
        $firstDay = $month->copy()->startOfMonth()->startOfDay();

        return $firstDay->day(min($day, $firstDay->daysInMonth));
    }
}
