<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum CategoryType: string
{
    use HasOptions;

    case Income = 'income';
    case Expense = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Income => 'Receita',
            self::Expense => 'Despesa',
        };
    }
}
