<?php

namespace App\Services\Quotes;

use App\Contracts\ForeignQuoteProvider;
use App\Domain\Investments\Quantity;
use App\Domain\Investments\Quote;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cotações de ações e ETFs dos EUA via Finnhub (plano gratuito: 60 consultas/min).
 * Ticker desconhecido volta com preço 0 e é ignorado.
 */
class FinnhubQuoteProvider implements ForeignQuoteProvider
{
    public function latest(array $tickers): array
    {
        $quotes = [];

        foreach (array_unique($tickers) as $ticker) {
            $quote = $this->fetch($ticker);

            if ($quote !== null) {
                $quotes[$ticker] = $quote;
            }
        }

        return $quotes;
    }

    private function fetch(string $ticker): ?Quote
    {
        try {
            $response = Http::baseUrl((string) config('services.finnhub.url'))
                ->timeout(10)
                ->retry(2, 500, throw: false)
                ->acceptJson()
                ->get('/api/v1/quote', ['symbol' => $ticker, 'token' => config('services.finnhub.token')]);
        } catch (ConnectionException $e) {
            Log::warning("Cotação {$ticker}: sem conexão com a Finnhub ({$e->getMessage()}).");

            return null;
        }

        $price = $response->json('c');
        $time = $response->json('t');

        if (! $response->successful() || ! is_numeric($price) || (float) $price <= 0 || ! is_numeric($time) || (int) $time === 0) {
            Log::warning("Cotação {$ticker}: Finnhub respondeu {$response->status()} sem preço.");

            return null;
        }

        $date = Carbon::createFromTimestamp((int) $time)->setTimezone((string) config('app.timezone'))->startOfDay();

        return new Quote($ticker, (string) Quantity::parse((float) $price), $date);
    }
}
