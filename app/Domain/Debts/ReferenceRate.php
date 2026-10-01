<?php

namespace App\Domain\Debts;

use App\Contracts\InterestRateProvider;
use App\Models\InterestRate;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * TR (Taxa Referencial) para corrigir o saldo de financiamentos. A parcela que vence em 23/10 usa a TR do
 * período de 23/09 a 23/10. Sem a TR do período (parcelas futuras), usa a última conhecida até aquela data.
 */
class ReferenceRate
{
    public const MAX_YEARS_PER_REQUEST = 10;

    public function __construct(
        private readonly InterestRateProvider $provider,
    ) {}

    /**
     * Busca e grava a TR dos períodos que começam entre as datas (repetir não duplica). Falha é registrada e devolve 0.
     */
    public function fetch(Carbon $from, Carbon $to): int
    {
        $stored = 0;
        $start = $from->copy()->startOfDay();

        while ($start->lte($to)) {
            $end = $start->copy()->addYears(self::MAX_YEARS_PER_REQUEST)->subDay()->min($to);

            try {
                $rates = $this->provider->tr($start, $end);
            } catch (Throwable $e) {
                Log::error('Falha ao buscar a TR: '.$e->getMessage());

                return $stored;
            }

            foreach ($rates as $date => $rate) {
                InterestRate::updateOrCreate(['series' => InterestRate::TR, 'date' => $date], ['rate' => $rate]);
                $stored++;
            }

            $start = $end->copy()->addDay();
        }

        return $stored;
    }

    /**
     * TR em % ao mês usada na parcela com este vencimento ('0' se ainda não há TR gravada).
     */
    public function percentFor(Carbon $dueDate): string
    {
        $start = $dueDate->copy()->startOfDay()->subMonthNoOverflow();

        return (string) (InterestRate::query()->where('series', InterestRate::TR)->whereDate('date', '<=', $start)->orderByDesc('date')->value('rate')
            ?? InterestRate::query()->where('series', InterestRate::TR)->orderBy('date')->value('rate')
            ?? '0');
    }

    /**
     * Fração para multiplicar o saldo (ex.: 0,1661% → 0.001661).
     */
    public function fractionFor(Carbon $dueDate): BigDecimal
    {
        return BigDecimal::of($this->percentFor($dueDate))->dividedBy(100, 10, RoundingMode::HalfUp);
    }

    public function latest(): ?InterestRate
    {
        return InterestRate::query()->where('series', InterestRate::TR)->orderByDesc('date')->first();
    }
}
