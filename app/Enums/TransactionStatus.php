<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum TransactionStatus: string
{
    use HasOptions;

    case Scheduled = 'scheduled';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Previsto',
            self::Paid => 'Pago',
        };
    }
}
