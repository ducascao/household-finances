<?php

namespace App\Console\Commands;

use App\Domain\Currency\ExchangeRates;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class FetchExchangeRatesCommand extends Command
{
    protected $signature = 'app:fetch-exchange-rates {--from= : Data inicial AAAA-MM-DD (padrão: 10 dias atrás)} {--currency=* : Moedas (padrão: as usadas nas contas)}';

    protected $description = 'Carrega o câmbio PTAX do Banco Central';

    public function handle(ExchangeRates $rates): int
    {
        $from = $this->option('from') ? Carbon::parse((string) $this->option('from')) : today()->subDays(10);
        /** @var list<string> $currencies */
        $currencies = array_map('strtoupper', (array) $this->option('currency'));

        $stored = $rates->fetch($from, today(), $currencies !== [] ? $currencies : null);

        $this->info("{$stored} taxa(s) de câmbio gravada(s) desde {$from->format('d/m/Y')}.");

        return self::SUCCESS;
    }
}
