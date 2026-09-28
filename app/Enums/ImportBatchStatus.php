<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ImportBatchStatus: string
{
    use HasOptions;

    case Reviewing = 'reviewing';
    case Completed = 'completed';
    case Discarded = 'discarded';

    public function label(): string
    {
        return match ($this) {
            self::Reviewing => 'Em revisão',
            self::Completed => 'Concluída',
            self::Discarded => 'Descartada',
        };
    }
}
