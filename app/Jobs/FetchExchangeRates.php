<?php

namespace App\Jobs;

use App\Domain\Currency\ExchangeRates;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Câmbio PTAX dos últimos 10 dias (dias úteis, depois da publicação do fechamento, ~13h).
 */
class FetchExchangeRates implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function handle(ExchangeRates $rates): void
    {
        $rates->fetch(today()->subDays(10), today());
    }
}
