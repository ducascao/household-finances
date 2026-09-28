<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ImportLineAction: string
{
    use HasOptions;

    case Import = 'import';
    case Settle = 'settle';
    case Skip = 'skip';

    public function label(): string
    {
        return match ($this) {
            self::Import => 'Importar',
            self::Settle => 'Baixar previsto',
            self::Skip => 'Ignorar',
        };
    }
}
