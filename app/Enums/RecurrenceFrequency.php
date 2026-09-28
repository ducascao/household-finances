<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum RecurrenceFrequency: string
{
    use HasOptions;

    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case EveryNMonths = 'every_n_months';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Semanal',
            self::Monthly => 'Mensal',
            self::EveryNMonths => 'A cada N meses',
            self::Yearly => 'Anual',
        };
    }

    public function usesDayOfMonth(): bool
    {
        return $this !== self::Weekly;
    }
}
