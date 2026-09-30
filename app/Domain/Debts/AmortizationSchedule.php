<?php

namespace App\Domain\Debts;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Tabelas Price e SAC (valores em centavos, taxa em % ao mês).
 *
 * - Juros de cada mês = saldo × taxa, arredondados ao centavo.
 * - Price: parcela = P·i ÷ (1 − (1+i)⁻ⁿ), arredondada ao centavo; amortização = parcela − juros.
 * - SAC: amortização = P ÷ n (arredondada para baixo); juros sobre o saldo.
 * - A última parcela amortiza o saldo que restar (absorve os centavos) e zera o saldo.
 * - Vencimentos mensais a partir do primeiro, mantendo o dia (31 cai no último dia em meses curtos).
 */
class AmortizationSchedule
{
    /**
     * Price com número de parcelas (parcela calculada) ou com parcela fixa (prazo calculado, para "reduzir o prazo").
     *
     * @return list<ScheduleRow>
     */
    public static function price(int $balance, string $monthlyRate, ?int $count, Carbon $firstDue, int $firstNumber = 1, ?int $fixedPayment = null): array
    {
        $rate = self::rate($monthlyRate);
        $payment = $fixedPayment ?? self::pricePayment($balance, $rate, $count ?? throw new InvalidArgumentException('Informe o número de parcelas.'));

        return self::build($balance, $rate, $count, $firstDue, $firstNumber, fn (int $interest, int $remaining): int => $payment - $interest);
    }

    /**
     * SAC com número de parcelas (amortização calculada) ou com amortização fixa (prazo calculado).
     *
     * @return list<ScheduleRow>
     */
    public static function sac(int $balance, string $monthlyRate, ?int $count, Carbon $firstDue, int $firstNumber = 1, ?int $fixedAmortization = null): array
    {
        $amortization = $fixedAmortization ?? intdiv($balance, $count ?? throw new InvalidArgumentException('Informe o número de parcelas.'));

        return self::build($balance, self::rate($monthlyRate), $count, $firstDue, $firstNumber, fn (int $interest, int $remaining): int => $amortization);
    }

    /**
     * Parcela da Price em centavos.
     */
    public static function pricePayment(int $balance, BigDecimal $rate, int $count): int
    {
        if ($count < 1) {
            throw new InvalidArgumentException('Informe ao menos uma parcela.');
        }

        if ($rate->isZero()) {
            return (int) ceil($balance / $count);
        }

        // (1 + i)^-n com precisão alta.
        $discount = BigDecimal::one()->dividedBy(BigDecimal::one()->plus($rate)->power($count), 20, RoundingMode::HalfUp);

        return BigDecimal::of($balance)
            ->multipliedBy($rate)
            ->dividedBy(BigDecimal::one()->minus($discount), 20, RoundingMode::HalfUp)
            ->toScale(0, RoundingMode::HalfUp)
            ->toInt();
    }

    public static function dueDate(Carbon $firstDue, int $offset): Carbon
    {
        return $firstDue->copy()->startOfDay()->addMonthsNoOverflow($offset);
    }

    /**
     * @param  \Closure(int $interest, int $remaining): int  $amortizationFor
     * @return list<ScheduleRow>
     */
    private static function build(int $balance, BigDecimal $rate, ?int $count, Carbon $firstDue, int $firstNumber, \Closure $amortizationFor): array
    {
        $rows = [];

        for ($i = 0; $balance > 0; $i++) {
            if ($i >= 1200) {
                throw new InvalidArgumentException('A parcela não cobre os juros: a dívida nunca seria quitada.');
            }

            $interest = BigDecimal::of($balance)->multipliedBy($rate)->toScale(0, RoundingMode::HalfUp)->toInt();
            $isLast = $count !== null && $i === $count - 1;
            $amortization = $isLast ? $balance : $amortizationFor($interest, $balance);

            if ($amortization <= 0) {
                throw new InvalidArgumentException('A parcela não cobre os juros: a dívida nunca seria quitada.');
            }

            $amortization = min($amortization, $balance);
            $balance -= $amortization;

            $rows[] = new ScheduleRow($firstNumber + $i, self::dueDate($firstDue, $i), $amortization, $interest, $balance);
        }

        return $rows;
    }

    private static function rate(string $monthlyRate): BigDecimal
    {
        return BigDecimal::of($monthlyRate)->dividedBy(100, 12, RoundingMode::HalfUp);
    }
}
