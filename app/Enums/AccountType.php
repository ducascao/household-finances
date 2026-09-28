<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum AccountType: string
{
    use HasOptions;

    case Checking = 'checking';
    case Savings = 'savings';
    case Cash = 'cash';
    case CreditCard = 'credit_card';
    case Brokerage = 'brokerage';

    public function label(): string
    {
        return match ($this) {
            self::Checking => 'Conta corrente',
            self::Savings => 'Poupança',
            self::Cash => 'Dinheiro',
            self::CreditCard => 'Cartão de crédito',
            self::Brokerage => 'Corretora',
        };
    }
}
