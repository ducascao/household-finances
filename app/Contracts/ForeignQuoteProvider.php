<?php

namespace App\Contracts;

/**
 * Cotações de ativos negociados fora do Brasil (moeda da conta). Implementação real:
 * App\Services\Quotes\FinnhubQuoteProvider. Testes usam Http::fake().
 */
interface ForeignQuoteProvider extends QuoteProvider {}
