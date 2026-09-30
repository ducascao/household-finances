<?php

namespace App\Domain\Currency;

use Illuminate\Support\Carbon;
use RuntimeException;

class MissingExchangeRate extends RuntimeException
{
    public static function for(string $currency, Carbon $date): self
    {
        return new self("Sem câmbio de {$currency} até {$date->format('d/m/Y')}: o câmbio precisa ser atualizado.");
    }
}
