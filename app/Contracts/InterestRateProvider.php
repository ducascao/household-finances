<?php

namespace App\Contracts;

use Illuminate\Support\Carbon;

/**
 * Séries de taxas do Banco Central. Implementação real: App\Services\Rates\BcbSgsProvider (SGS).
 */
interface InterestRateProvider
{
    /**
     * Taxas diárias do CDI (em %) entre as datas, indexadas por "AAAA-MM-DD".
     *
     * @return array<string, string>
     *
     * @throws \RuntimeException quando a fonte não responde
     */
    public function cdi(Carbon $from, Carbon $to): array;

    /**
     * TR (em % ao mês) dos períodos que começam entre as datas, indexada pela data de início "AAAA-MM-DD"
     * (ex.: 2026-09-23 = período de 23/09 a 23/10).
     *
     * @return array<string, string>
     *
     * @throws \RuntimeException quando a fonte não responde
     */
    public function tr(Carbon $from, Carbon $to): array;
}
