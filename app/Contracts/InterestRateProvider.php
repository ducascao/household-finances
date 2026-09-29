<?php

namespace App\Contracts;

use Illuminate\Support\Carbon;

/**
 * Fonte da série diária do CDI. Implementação real: App\Services\Rates\BcbSgsProvider (SGS do Banco Central).
 */
interface InterestRateProvider
{
    /**
     * Taxas diárias (em %) entre as datas, indexadas por "AAAA-MM-DD".
     *
     * @return array<string, string>
     *
     * @throws \RuntimeException quando a fonte não responde
     */
    public function cdi(Carbon $from, Carbon $to): array;
}
