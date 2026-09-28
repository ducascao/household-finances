<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ImportFormat: string
{
    use HasOptions;

    case Ofx = 'ofx';
    case Csv = 'csv';

    public function label(): string
    {
        return match ($this) {
            self::Ofx => 'OFX',
            self::Csv => 'CSV',
        };
    }
}
