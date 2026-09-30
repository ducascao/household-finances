<?php

namespace App\Services\Rates;

use App\Contracts\ExchangeRateProvider;
use App\Domain\Investments\Quantity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * PTAX do Banco Central (API Olinda): cotação de venda do boletim de fechamento de cada dia útil.
 */
class BcbPtaxProvider implements ExchangeRateProvider
{
    public function rates(string $currency, Carbon $from, Carbon $to): array
    {
        $query = http_build_query([
            '@moeda' => "'{$currency}'",
            '@dataInicial' => "'".$from->format('m-d-Y')."'",
            '@dataFinalCotacao' => "'".$to->format('m-d-Y')."'",
            '$format' => 'json',
            '$select' => 'cotacaoVenda,dataHoraCotacao,tipoBoletim',
        ], '', '&', PHP_QUERY_RFC3986);

        $response = Http::baseUrl((string) config('services.bcb.ptax_url'))
            ->timeout(20)
            ->retry(2, 1000, throw: false)
            ->acceptJson()
            ->get('/CotacaoMoedaPeriodo(moeda=@moeda,dataInicial=@dataInicial,dataFinalCotacao=@dataFinalCotacao)?'.$query);

        if (! $response->successful() || ! is_array($response->json('value'))) {
            throw new RuntimeException("PTAX/Banco Central respondeu {$response->status()}.");
        }

        $rates = [];

        foreach ($response->json('value') as $row) {
            if (! is_array($row) || ($row['tipoBoletim'] ?? null) !== 'Fechamento' || ! is_numeric($row['cotacaoVenda'] ?? null)) {
                continue;
            }

            $date = Carbon::parse((string) $row['dataHoraCotacao'])->toDateString();
            $rates[$date] = (string) Quantity::parse((float) $row['cotacaoVenda']);
        }

        return $rates;
    }
}
