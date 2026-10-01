<?php

namespace App\Domain\Debts;

use App\Enums\DebtSystem;
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
 *
 * Com correção pela TR (financiamento imobiliário), em cada parcela: saldo corrigido = saldo × (1 + TR do período);
 * SAC: amortização = saldo corrigido ÷ parcelas restantes; Price: parcela recalculada sobre o saldo corrigido e o
 * prazo restante; juros sobre o saldo corrigido. Encargos (seguro % do saldo corrigido + fixo) somam na parcela.
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
     * Tabela com as condições completas da dívida (TR e encargos). Sem TR, igual a price()/sac() mais os encargos.
     *
     * @return list<ScheduleRow>
     */
    public static function schedule(DebtTerms $terms, int $balance, int $count, Carbon $firstDue, int $firstNumber = 1): array
    {
        if ($terms->trFor === null) {
            $rows = $terms->system === DebtSystem::Price
                ? self::price($balance, $terms->monthlyRate, $count, $firstDue, $firstNumber)
                : self::sac($balance, $terms->monthlyRate, $count, $firstDue, $firstNumber);

            return self::withCharges($terms, $rows);
        }

        if ($count < 1) {
            throw new InvalidArgumentException('Informe ao menos uma parcela.');
        }

        $rate = self::rate($terms->monthlyRate);
        $rows = [];

        for ($i = 0; $i < $count && $balance > 0; $i++) {
            $due = self::dueDate($firstDue, $i);
            $correction = BigDecimal::of($balance)->multipliedBy(($terms->trFor)($due))->toScale(0, RoundingMode::HalfUp)->toInt();
            $corrected = $balance + $correction;
            $remaining = $count - $i;
            $interest = BigDecimal::of($corrected)->multipliedBy($rate)->toScale(0, RoundingMode::HalfUp)->toInt();

            $amortization = match (true) {
                $remaining === 1 => $corrected,
                $terms->system === DebtSystem::Price => self::pricePayment($corrected, $rate, $remaining) - $interest,
                default => BigDecimal::of($corrected)->dividedBy($remaining, 0, RoundingMode::HalfUp)->toInt(),
            };

            if ($amortization <= 0) {
                throw new InvalidArgumentException('A parcela não cobre os juros: a dívida nunca seria quitada.');
            }

            $amortization = min($amortization, $corrected);
            $balance = $corrected - $amortization;

            $rows[] = new ScheduleRow($firstNumber + $i, $due, $amortization, $interest, $balance, $correction, self::charges($terms, $corrected));
        }

        return $rows;
    }

    /**
     * Soma os encargos (seguro sobre o saldo antes da parcela + fixo) a uma tabela sem TR.
     *
     * @param  list<ScheduleRow>  $rows
     * @return list<ScheduleRow>
     */
    public static function withCharges(DebtTerms $terms, array $rows): array
    {
        return array_map(fn (ScheduleRow $row): ScheduleRow => new ScheduleRow(
            $row->number, $row->dueDate, $row->amortization, $row->interest, $row->balanceAfter, 0,
            self::charges($terms, $row->balanceAfter + $row->amortization),
        ), $rows);
    }

    /**
     * Encargos da parcela: seguro proporcional ao saldo (corrigido) + valor fixo.
     */
    public static function charges(DebtTerms $terms, int $balance): int
    {
        $insurance = BigDecimal::of($balance)->multipliedBy(self::rate($terms->insuranceRate))->toScale(0, RoundingMode::HalfUp)->toInt();

        return $insurance + $terms->monthlyFee;
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
