<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Como a taxa de juros foi informada. Guardamos sempre a taxa equivalente ao mês.
 */
enum RatePeriod: string
{
    use HasOptions;

    case Monthly = 'monthly';
    case AnnualEffective = 'annual_effective';
    case AnnualNominal = 'annual_nominal';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => '% ao mês',
            self::AnnualEffective => '% ao ano (efetiva)',
            self::AnnualNominal => '% ao ano (nominal)',
        };
    }

    /**
     * Taxa em % ao mês, com 8 casas: efetiva anual → (1 + a)^(1/12) − 1; nominal anual → a ÷ 12.
     */
    public function toMonthly(string $rate): string
    {
        $value = (float) $rate;

        $monthly = match ($this) {
            self::Monthly => $value,
            self::AnnualEffective => ((1 + $value / 100) ** (1 / 12) - 1) * 100,
            self::AnnualNominal => $value / 12,
        };

        return number_format($monthly, 8, '.', '');
    }
}
