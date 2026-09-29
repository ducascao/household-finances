<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum AssetOperationType: string
{
    use HasOptions;

    case Buy = 'buy';
    case Sell = 'sell';
    case Split = 'split';
    case ReverseSplit = 'reverse_split';

    public function label(): string
    {
        return match ($this) {
            self::Buy => 'Compra',
            self::Sell => 'Venda',
            self::Split => 'Desdobramento',
            self::ReverseSplit => 'Grupamento',
        };
    }

    public function isTrade(): bool
    {
        return $this === self::Buy || $this === self::Sell;
    }
}
