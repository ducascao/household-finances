<?php

namespace App\Services\Quotes;

use App\Contracts\QuoteProvider;
use App\Domain\Investments\Quantity;
use App\Domain\Investments\Quote;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cotações via brapi.dev. Uma requisição por ticker (o plano gratuito limita a um por chamada);
 * erro num ticker não impede os demais.
 */
class BrapiQuoteProvider implements QuoteProvider
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
            $response = Http::baseUrl((string) config('services.brapi.url'))
                ->timeout(10)
                ->retry(2, 500, throw: false)
                ->acceptJson()
                ->get('/api/quote/'.rawurlencode($ticker), array_filter(['token' => config('services.brapi.token')]));
        } catch (ConnectionException $e) {
            Log::warning("Cotação {$ticker}: sem conexão com a brapi ({$e->getMessage()}).");

            return null;
        }

        if (! $response->successful()) {
            Log::warning("Cotação {$ticker}: brapi respondeu {$response->status()}.");

            return null;
        }

        $result = $response->json('results.0');

        if (! is_array($result) || ! is_numeric($result['regularMarketPrice'] ?? null)) {
            Log::warning("Cotação {$ticker}: resposta sem preço.");

            return null;
        }

        $time = $result['regularMarketTime'] ?? null;
        $date = is_string($time) || is_int($time)
            ? (is_int($time) ? Carbon::createFromTimestamp($time) : Carbon::parse($time))->setTimezone((string) config('app.timezone'))->startOfDay()
            : today();

        return new Quote($ticker, (string) Quantity::parse((float) $result['regularMarketPrice']), $date);
    }
}
