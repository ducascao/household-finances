<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum PriceSource: string
{
    use HasOptions;

    case Api = 'api';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Api => 'Automática',
            self::Manual => 'Manual',
        };
    }
}
