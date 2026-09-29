<?php

namespace App\Domain\Investments;

use App\Contracts\InterestRateProvider;
use App\Models\InterestRate;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Série diária do CDI: carga pela API do Banco Central e acumulado de um período.
 */
class Cdi
{
    public const MAX_YEARS_PER_REQUEST = 10;

    public function __construct(
        private readonly InterestRateProvider $provider,
    ) {}

    /**
     * Busca e grava as taxas do período (repetir não duplica). Falha é registrada e devolve 0.
     */
    public function fetch(Carbon $from, Carbon $to): int
    {
        $stored = 0;
        $start = $from->copy()->startOfDay();

        while ($start->lte($to)) {
            $end = $start->copy()->addYears(self::MAX_YEARS_PER_REQUEST)->subDay()->min($to);

            try {
                $rates = $this->provider->cdi($start, $end);
            } catch (Throwable $e) {
                Log::error('Falha ao buscar o CDI: '.$e->getMessage());

                return $stored;
            }

            foreach ($rates as $date => $rate) {
                InterestRate::updateOrCreate(['series' => InterestRate::CDI, 'date' => $date], ['rate' => $rate]);
                $stored++;
            }

            $start = $end->copy()->addDay();
        }

        return $stored;
    }

    /**
     * CDI acumulado entre as datas (inclusive), em %: (∏(1 + taxa/100) − 1) × 100.
     */
    public function accumulated(Carbon $from, Carbon $to): ?float
    {
        $rates = InterestRate::query()
            ->where('series', InterestRate::CDI)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')
            ->pluck('rate');

        if ($rates->isEmpty()) {
            return null;
        }

        $factor = BigDecimal::one();

        foreach ($rates as $rate) {
            $factor = $factor->multipliedBy(BigDecimal::one()->plus(BigDecimal::of((string) $rate)->dividedBy(100, 12, RoundingMode::HalfUp)))
                ->toScale(12, RoundingMode::HalfUp);
        }

        return $factor->minus(1)->multipliedBy(100)->toScale(4, RoundingMode::HalfUp)->toFloat();
    }
}
