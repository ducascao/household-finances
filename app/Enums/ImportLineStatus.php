<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ImportLineStatus: string
{
    use HasOptions;

    case New = 'new';
    case Duplicate = 'duplicate';
    case Match = 'match';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Nova',
            self::Duplicate => 'Duplicada',
            self::Match => 'Corresponde a previsto',
        };
    }
}
