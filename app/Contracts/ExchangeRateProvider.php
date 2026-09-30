<?php

namespace App\Contracts;

use Illuminate\Support\Carbon;

/**
 * Fonte do câmbio diário. Implementação real: App\Services\Rates\BcbPtaxProvider.
 */
interface ExchangeRateProvider
{
    /**
     * Reais por 1 unidade da moeda, por dia útil do período, indexado por "AAAA-MM-DD".
     *
     * @return array<string, string>
     *
     * @throws \RuntimeException quando a fonte não responde
     */
    public function rates(string $currency, Carbon $from, Carbon $to): array;
}
