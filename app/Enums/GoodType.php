<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum GoodType: string
{
    use HasOptions;

    case Property = 'property';
    case Vehicle = 'vehicle';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Property => 'Imóvel',
            self::Vehicle => 'Veículo',
            self::Other => 'Outro bem',
        };
    }
}
