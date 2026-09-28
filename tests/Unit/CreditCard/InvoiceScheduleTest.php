<?php

use App\Domain\CreditCard\InvoiceSchedule;
use App\Models\CreditCard;
use Illuminate\Support\Carbon;

function card(int $closingDay, int $dueDay): CreditCard
{
    $card = new CreditCard;
    $card->closing_day = $closingDay;
    $card->due_day = $dueDay;

    return $card;
}

/**
 * @return array{string, string, string}
 */
function invoiceDates(CreditCard $card, string $purchase): array
{
    $dates = InvoiceSchedule::forPurchase($card, Carbon::parse($purchase));

    return [$dates['reference_month']->format('Y-m'), $dates['closing_date']->toDateString(), $dates['due_date']->toDateString()];
}

it('compra cai na fatura correta conforme o dia de fechamento', function (int $closing, int $due, string $purchase, array $expected) {
    expect(invoiceDates(card($closing, $due), $purchase))->toBe($expected);
})->with([
    // fecha 3, vence 10 (mesmo mês)
    'antes do fechamento' => [3, 10, '2026-09-02', ['2026-09', '2026-09-03', '2026-09-10']],
    'no dia do fechamento vai para a seguinte' => [3, 10, '2026-09-03', ['2026-10', '2026-10-03', '2026-10-10']],
    'depois do fechamento' => [3, 10, '2026-09-25', ['2026-10', '2026-10-03', '2026-10-10']],
    // fecha 25, vence 5 (mês seguinte)
    'vencimento antes do fechamento: vence no mês seguinte' => [25, 5, '2026-09-24', ['2026-10', '2026-09-25', '2026-10-05']],
    'no fechamento, com vencimento no mês seguinte' => [25, 5, '2026-09-25', ['2026-11', '2026-10-25', '2026-11-05']],
    'virada de ano' => [25, 5, '2026-12-26', ['2027-02', '2027-01-25', '2027-02-05']],
    'dezembro antes do fechamento' => [25, 5, '2026-12-10', ['2027-01', '2026-12-25', '2027-01-05']],
    // fecha 31
    'fechamento 31 em fevereiro cai no dia 28' => [31, 10, '2027-02-27', ['2027-03', '2027-02-28', '2027-03-10']],
    'no dia 28/02 com fechamento 31 vai para março' => [31, 10, '2027-02-28', ['2027-04', '2027-03-31', '2027-04-10']],
    'fechamento 31 em ano bissexto' => [31, 10, '2028-02-28', ['2028-03', '2028-02-29', '2028-03-10']],
    // vencimento 31
    'vencimento 31 em mês de 30 dias' => [20, 31, '2026-09-05', ['2026-09', '2026-09-20', '2026-09-30']],
    // mesmo dia de fechamento e vencimento: vence no mês seguinte
    'fechamento e vencimento no mesmo dia' => [10, 10, '2026-09-09', ['2026-10', '2026-09-10', '2026-10-10']],
]);

it('as datas pelo mês de referência batem com as da compra', function (int $closing, int $due, string $purchase) {
    $card = card($closing, $due);
    $byPurchase = InvoiceSchedule::forPurchase($card, Carbon::parse($purchase));
    $byMonth = InvoiceSchedule::forReferenceMonth($card, $byPurchase['reference_month']);

    expect($byMonth['closing_date']->toDateString())->toBe($byPurchase['closing_date']->toDateString())
        ->and($byMonth['due_date']->toDateString())->toBe($byPurchase['due_date']->toDateString());
})->with([
    [3, 10, '2026-09-25'],
    [25, 5, '2026-12-26'],
    [31, 10, '2027-02-27'],
    [10, 10, '2026-09-09'],
]);
