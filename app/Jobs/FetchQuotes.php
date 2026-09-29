<?php

namespace App\Jobs;

use App\Domain\Investments\PriceBook;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Busca diária das cotações (dias úteis, depois do fechamento da B3).
 */
class FetchQuotes implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function handle(PriceBook $prices): void
    {
        $result = $prices->fetchAll();

        Log::info("Cotações: {$result['updated']} atualizada(s)".($result['missing'] !== [] ? '; sem cotação: '.implode(', ', $result['missing']) : '').'.');
    }
}
