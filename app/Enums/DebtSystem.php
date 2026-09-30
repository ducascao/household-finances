<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum DebtSystem: string
{
    use HasOptions;

    case Price = 'price';
    case Sac = 'sac';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Price => 'Price (parcelas iguais)',
            self::Sac => 'SAC (amortização constante)',
            self::Custom => 'Personalizada (tabela informada)',
        };
    }
}
