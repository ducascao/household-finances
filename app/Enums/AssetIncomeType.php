<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum AssetIncomeType: string
{
    use HasOptions;

    case Dividend = 'dividend';
    case Jcp = 'jcp';
    case FundIncome = 'fund_income';

    public function label(): string
    {
        return match ($this) {
            self::Dividend => 'Dividendo',
            self::Jcp => 'JCP',
            self::FundIncome => 'Rendimento de FII',
        };
    }

    /**
     * Subcategoria de "Rendimentos" sugerida para o lançamento.
     */
    public function categoryName(): string
    {
        return match ($this) {
            self::Dividend => 'Dividendos',
            self::Jcp => 'JCP',
            self::FundIncome => 'Rendimentos de FII',
        };
    }
}
