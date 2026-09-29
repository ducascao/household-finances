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
    case Contribution = 'contribution';
    case Withdrawal = 'withdrawal';

    public function label(): string
    {
        return match ($this) {
            self::Buy => 'Compra',
            self::Sell => 'Venda',
            self::Split => 'Desdobramento',
            self::ReverseSplit => 'Grupamento',
            self::Contribution => 'Aporte',
            self::Withdrawal => 'Resgate',
        };
    }

    public function isTrade(): bool
    {
        return $this === self::Buy || $this === self::Sell;
    }

    public function isCashFlow(): bool
    {
        return $this === self::Contribution || $this === self::Withdrawal;
    }

    /**
     * Operações permitidas para o tipo de ativo.
     *
     * @return array<string, string>
     */
    public static function optionsFor(AssetType $type): array
    {
        return array_filter(
            self::options(),
            fn (string $label, string $value): bool => self::from($value)->isCashFlow() === $type->isValuedByBalance(),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
