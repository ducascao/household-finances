<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum AssetType: string
{
    use HasOptions;

    case Stock = 'stock';
    case RealEstateFund = 'fii';
    case Etf = 'etf';
    case Bdr = 'bdr';
    case Reit = 'reit';
    case FixedIncome = 'fixed_income';
    case Pension = 'pension';

    public function label(): string
    {
        return match ($this) {
            self::Stock => 'Ação',
            self::RealEstateFund => 'FII',
            self::Etf => 'ETF',
            self::Bdr => 'BDR',
            self::Reit => 'REIT',
            self::FixedIncome => 'Renda fixa',
            self::Pension => 'Previdência',
        };
    }

    /**
     * Renda fixa e previdência: sem ticker nem cotação; valor pelo saldo informado.
     */
    public function isValuedByBalance(): bool
    {
        return $this === self::FixedIncome || $this === self::Pension;
    }
}
