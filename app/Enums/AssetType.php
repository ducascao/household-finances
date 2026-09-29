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

    public function label(): string
    {
        return match ($this) {
            self::Stock => 'Ação',
            self::RealEstateFund => 'FII',
            self::Etf => 'ETF',
            self::Bdr => 'BDR',
        };
    }
}
