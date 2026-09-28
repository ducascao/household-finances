<?php

namespace App\Domain\CreditCard;

use App\Models\CreditCard;
use Illuminate\Support\Carbon;

/**
 * Datas das faturas de um cartão.
 *
 * - Compra antes do dia de fechamento cai na fatura que fecha naquele mês; no dia do fechamento ou depois, na seguinte.
 * - Vencimento: se o dia de vencimento é depois do dia de fechamento, vence no mesmo mês do fechamento; senão, no mês seguinte.
 * - Mês de referência da fatura = mês do vencimento.
 * - Dias 29–31 caem no último dia em meses mais curtos.
 */
class InvoiceSchedule
{
    /**
     * @return array{reference_month: Carbon, closing_date: Carbon, due_date: Carbon}
     */
    public static function forPurchase(CreditCard $card, Carbon $purchaseDate): array
    {
        $date = $purchaseDate->copy()->startOfDay();
        $closing = self::dayIn($date, $card->closing_day);

        if ($date->gte($closing)) {
            $closing = self::dayIn($date->copy()->startOfMonth()->addMonthNoOverflow(), $card->closing_day);
        }

        return self::fromClosing($card, $closing);
    }

    /**
     * @return array{reference_month: Carbon, closing_date: Carbon, due_date: Carbon}
     */
    public static function forReferenceMonth(CreditCard $card, Carbon $referenceMonth): array
    {
        $month = $referenceMonth->copy()->startOfMonth()->startOfDay();
        $closingMonth = self::dueInSameMonth($card) ? $month : $month->copy()->subMonthNoOverflow();

        return self::fromClosing($card, self::dayIn($closingMonth, $card->closing_day));
    }

    /**
     * @return array{reference_month: Carbon, closing_date: Carbon, due_date: Carbon}
     */
    private static function fromClosing(CreditCard $card, Carbon $closing): array
    {
        $dueMonth = self::dueInSameMonth($card) ? $closing->copy()->startOfMonth() : $closing->copy()->startOfMonth()->addMonthNoOverflow();
        $due = self::dayIn($dueMonth, $card->due_day);

        return [
            'reference_month' => $due->copy()->startOfMonth(),
            'closing_date' => $closing,
            'due_date' => $due,
        ];
    }

    private static function dueInSameMonth(CreditCard $card): bool
    {
        return $card->due_day > $card->closing_day;
    }

    private static function dayIn(Carbon $month, int $day): Carbon
    {
        $first = $month->copy()->startOfMonth()->startOfDay();

        return $first->day(min($day, $first->daysInMonth));
    }
}
