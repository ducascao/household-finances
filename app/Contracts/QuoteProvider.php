<?php

namespace App\Contracts;

use App\Domain\Investments\Quote;

/**
 * Fonte de cotações de ativos da B3. Implementação real: App\Services\Quotes\BrapiQuoteProvider.
 * Testes usam Http::fake() e nunca chamam a API.
 */
interface QuoteProvider
{
    /**
     * Última cotação de cada ticker. Tickers sem cotação (desconhecidos ou com erro) ficam de fora.
     *
     * @param  list<string>  $tickers
     * @return array<string, Quote>
     */
    public function latest(array $tickers): array;
}
