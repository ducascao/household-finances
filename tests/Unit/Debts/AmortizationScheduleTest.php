<?php

use App\Domain\Debts\AmortizationSchedule;
use App\Domain\Debts\ScheduleRow;
use Illuminate\Support\Carbon;

function table(array $rows): array
{
    return array_map(fn (ScheduleRow $r) => [$r->number, $r->dueDate->toDateString(), $r->amortization, $r->interest, $r->total(), $r->balanceAfter], $rows);
}

it('Price confere com o simulador de referência (Calculadora do Cidadão/BCB)', function () {
    // R$ 10.000,00 a 1% a.m. em 12x → parcela R$ 888,49
    $rows = AmortizationSchedule::price(1000000, '1', 12, Carbon::parse('2026-01-15'));

    expect(count($rows))->toBe(12)
        ->and(table(array_slice($rows, 0, 2)))->toBe([
            [1, '2026-01-15', 78849, 10000, 88849, 921151],
            [2, '2026-02-15', 79637, 9212, 88849, 841514],
        ])
        ->and(array_sum(array_map(fn ($r) => $r->amortization, $rows)))->toBe(1000000)
        ->and(end($rows)->balanceAfter)->toBe(0)
        ->and(array_unique(array_map(fn ($r) => $r->total(), array_slice($rows, 0, 11))))->toBe([88849])
        ->and(abs(end($rows)->total() - 88849))->toBeLessThanOrEqual(2);
});

it('Price confere em financiamento longo: R$ 300.000 a 0,95% a.m. em 360x', function () {
    // P·i ÷ (1 − (1,0095)⁻³⁶⁰) = 2.948,01 (conferido com cálculo independente em Decimal)
    $rows = AmortizationSchedule::price(30000000, '0.95', 360, Carbon::parse('2026-01-10'));

    expect($rows[0]->total())->toBe(294801)
        ->and($rows[0]->interest)->toBe(285000)
        ->and(count($rows))->toBe(360)
        ->and(end($rows)->balanceAfter)->toBe(0);
});

it('SAC confere: amortização constante, juros totais = P·i·(n+1)/2', function () {
    // R$ 10.000,00 a 1% a.m. em 12x → amortização 833,33; 1ª parcela 933,33; juros totais 650,00
    $rows = AmortizationSchedule::sac(1000000, '1', 12, Carbon::parse('2026-01-15'));

    expect(table([$rows[0]]))->toBe([[1, '2026-01-15', 83333, 10000, 93333, 916667]])
        ->and($rows[11]->amortization)->toBe(83337)
        ->and($rows[11]->interest)->toBe(833)
        ->and(array_sum(array_map(fn ($r) => $r->interest, $rows)))->toBe(65000)
        ->and(array_sum(array_map(fn ($r) => $r->amortization, $rows)))->toBe(1000000)
        ->and(end($rows)->balanceAfter)->toBe(0);
});

it('taxa zero divide o principal', function () {
    $rows = AmortizationSchedule::price(100000, '0', 3, Carbon::parse('2026-01-10'));

    expect(array_map(fn ($r) => [$r->amortization, $r->interest], $rows))->toBe([[33334, 0], [33334, 0], [33332, 0]]);
});

it('prazo calculado quando a parcela é fixa (reduzir o prazo)', function () {
    $rows = AmortizationSchedule::price(500000, '1', null, Carbon::parse('2026-01-10'), 5, fixedPayment: 88849);

    expect($rows[0]->number)->toBe(5)
        ->and(count($rows))->toBe(6)
        ->and(end($rows)->balanceAfter)->toBe(0)
        ->and(end($rows)->total())->toBeLessThanOrEqual(88849);
});

it('vencimento dia 31 cai no último dia em meses curtos', function () {
    $rows = AmortizationSchedule::sac(300000, '1', 3, Carbon::parse('2026-01-31'));

    expect(array_map(fn ($r) => $r->dueDate->toDateString(), $rows))->toBe(['2026-01-31', '2026-02-28', '2026-03-31']);
});

it('recusa parcela que não cobre os juros', function () {
    AmortizationSchedule::price(1000000, '1', null, Carbon::parse('2026-01-10'), fixedPayment: 5000);
})->throws(InvalidArgumentException::class, 'nunca seria quitada');
