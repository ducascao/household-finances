<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum AccountVisibility: string
{
    use HasOptions;

    case Private = 'private';
    case Shared = 'shared';

    public function label(): string
    {
        return match ($this) {
            self::Private => 'Pessoal',
            self::Shared => 'Compartilhada',
        };
    }
}
