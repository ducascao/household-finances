<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum Indexer: string
{
    use HasOptions;

    case Cdi = 'cdi';
    case Selic = 'selic';
    case Ipca = 'ipca';
    case Prefixed = 'prefixed';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cdi => 'CDI',
            self::Selic => 'Selic',
            self::Ipca => 'IPCA',
            self::Prefixed => 'Prefixado',
            self::Other => 'Outro',
        };
    }
}
