<?php

namespace App\Services\Rates;

use App\Contracts\InterestRateProvider;
use App\Domain\Investments\Quantity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * CDI diário (série 12) e TR por período mensal (série 226, pela data de início) do SGS/Banco Central.
 * A API aceita até 10 anos por consulta.
 */
class BcbSgsProvider implements InterestRateProvider
{
    public const CDI_SERIES = 12;

    public const TR_SERIES = 226;

    public function cdi(Carbon $from, Carbon $to): array
    {
        return $this->series(self::CDI_SERIES, $from, $to);
    }

    public function tr(Carbon $from, Carbon $to): array
    {
        return $this->series(self::TR_SERIES, $from, $to);
    }

    /**
     * @return array<string, string>
     */
    private function series(int $series, Carbon $from, Carbon $to): array
    {
        $response = Http::baseUrl((string) config('services.bcb.url'))
            ->timeout(20)
            ->retry(2, 1000, throw: false)
            ->acceptJson()
            ->get('/dados/serie/bcdata.sgs.'.$series.'/dados', [
                'formato' => 'json',
                'dataInicial' => $from->format('d/m/Y'),
                'dataFinal' => $to->format('d/m/Y'),
            ]);

        // Sem dados no intervalo (ex.: fim de semana), o SGS responde 404.
        if ($response->status() === 404) {
            return [];
        }

        if (! $response->successful() || ! is_array($response->json())) {
            throw new RuntimeException("SGS/Banco Central respondeu {$response->status()}.");
        }

        $rates = [];

        foreach ($response->json() as $row) {
            if (! is_array($row) || ! isset($row['data'], $row['valor'])) {
                continue;
            }

            $date = Carbon::createFromFormat('!d/m/Y', (string) $row['data']);

            if ($date !== null) {
                $rates[$date->toDateString()] = (string) Quantity::parse((string) $row['valor']);
            }
        }

        return $rates;
    }
}
