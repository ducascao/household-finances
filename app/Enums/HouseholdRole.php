<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum HouseholdRole: string
{
    use HasOptions;

    case Admin = 'admin';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Member => 'Membro',
        };
    }
}
