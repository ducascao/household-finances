<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ImportFormat: string
{
    use HasOptions;

    case Ofx = 'ofx';
    case Csv = 'csv';
    case Pdf = 'pdf';

    public function label(): string
    {
        return match ($this) {
            self::Ofx => 'OFX',
            self::Csv => 'CSV',
            self::Pdf => 'PDF',
        };
    }
}
