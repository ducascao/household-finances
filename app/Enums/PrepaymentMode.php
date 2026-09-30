<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum PrepaymentMode: string
{
    use HasOptions;

    case ReduceTerm = 'reduce_term';
    case ReduceInstallment = 'reduce_installment';

    public function label(): string
    {
        return match ($this) {
            self::ReduceTerm => 'Reduzir o prazo',
            self::ReduceInstallment => 'Reduzir a parcela',
        };
    }
}
