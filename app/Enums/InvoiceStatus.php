<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum InvoiceStatus: string
{
    use HasOptions;

    case Open = 'open';
    case Closed = 'closed';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Aberta',
            self::Closed => 'Fechada',
            self::Paid => 'Paga',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'info',
            self::Closed => 'warning',
            self::Paid => 'success',
        };
    }
}
