<?php

use App\Domain\Recurrences\RecurrenceSchedule;
use App\Enums\RecurrenceFrequency;
use App\Models\Recurrence;
use Illuminate\Support\Carbon;

function recurrence(RecurrenceFrequency $frequency, string $start, ?int $day = null, ?int $interval = null): Recurrence
{
    $recurrence = new Recurrence;
    $recurrence->frequency = $frequency;
    $recurrence->start_date = Carbon::parse($start);
    $recurrence->day_of_month = $day;
    $recurrence->interval_months = $interval;

    return $recurrence;
}

/**
 * @return list<string>
 */
function occurrences(Recurrence $recurrence, int $count): array
{
    $dates = [];
    $date = RecurrenceSchedule::first($recurrence);

    for ($i = 0; $i < $count; $i++) {
        $dates[] = $date->toDateString();
        $date = RecurrenceSchedule::after($recurrence, $date);
    }

    return $dates;
}

it('dia 31 em meses curtos cai no último dia do mês e volta ao 31 depois', function () {
    expect(occurrences(recurrence(RecurrenceFrequency::Monthly, '2026-01-31', 31), 6))->toBe([
        '2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30',
    ]);
});

it('dia 31 em fevereiro de ano bissexto cai no dia 29', function () {
    expect(occurrences(recurrence(RecurrenceFrequency::Monthly, '2028-01-31', 31), 3))->toBe([
        '2028-01-31', '2028-02-29', '2028-03-31',
    ]);
});

it('dia 30 e 29 também se ajustam a fevereiro', function () {
    expect(occurrences(recurrence(RecurrenceFrequency::Monthly, '2027-01-30', 30), 3))->toBe(['2027-01-30', '2027-02-28', '2027-03-30'])
        ->and(occurrences(recurrence(RecurrenceFrequency::Monthly, '2027-01-29', 29), 3))->toBe(['2027-01-29', '2027-02-28', '2027-03-29']);
});

it('começa no mês seguinte se o dia já passou na data de início', function () {
    expect(occurrences(recurrence(RecurrenceFrequency::Monthly, '2026-09-20', 10), 2))->toBe(['2026-10-10', '2026-11-10']);
});

it('a cada N meses mantém o alinhamento e o ajuste de fim de mês', function () {
    expect(occurrences(recurrence(RecurrenceFrequency::EveryNMonths, '2026-11-30', 31, 3), 4))->toBe([
        '2026-11-30', '2027-02-28', '2027-05-31', '2027-08-31',
    ]);
});

it('anual em 29/02 cai em 28/02 nos anos não bissextos', function () {
    expect(occurrences(recurrence(RecurrenceFrequency::Yearly, '2028-02-29', 29), 5))->toBe([
        '2028-02-29', '2029-02-28', '2030-02-28', '2031-02-28', '2032-02-29',
    ]);
});

it('semanal repete a cada 7 dias', function () {
    expect(occurrences(recurrence(RecurrenceFrequency::Weekly, '2026-09-25'), 3))->toBe(['2026-09-25', '2026-10-02', '2026-10-09']);
});

it('encontra a primeira ocorrência numa data ou depois', function () {
    $monthly = recurrence(RecurrenceFrequency::Monthly, '2026-01-31', 31);

    expect(RecurrenceSchedule::firstOnOrAfter($monthly, Carbon::parse('2026-02-15'))->toDateString())->toBe('2026-02-28')
        ->and(RecurrenceSchedule::firstOnOrAfter($monthly, Carbon::parse('2026-02-28'))->toDateString())->toBe('2026-02-28');
});

it('descreve a frequência em português', function () {
    expect(RecurrenceSchedule::describe(recurrence(RecurrenceFrequency::Monthly, '2026-01-10', 10)))->toBe('Mensal, dia 10')
        ->and(RecurrenceSchedule::describe(recurrence(RecurrenceFrequency::EveryNMonths, '2026-01-05', 5, 3)))->toBe('A cada 3 meses, dia 5')
        ->and(RecurrenceSchedule::describe(recurrence(RecurrenceFrequency::Yearly, '2028-02-29', 29)))->toBe('Anual, 29/02')
        ->and(RecurrenceSchedule::describe(recurrence(RecurrenceFrequency::Weekly, '2026-09-25')))->toBe('Semanal, sexta-feira');
});
