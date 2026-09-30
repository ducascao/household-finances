<?php

namespace App\Support;

use Brick\Money\Money;
use InvalidArgumentException;

/**
 * Formatação e leitura de valores no padrão brasileiro (R$ 1.234,56).
 */
class MoneyFormatter
{
    private const SYMBOLS = [
        'BRL' => 'R$',
        'USD' => 'US$',
        'EUR' => '€',
        'GBP' => '£',
    ];

    public static function format(Money $money): string
    {
        $currency = $money->getCurrency();
        $symbol = self::SYMBOLS[$currency->getCurrencyCode()] ?? $currency->getCurrencyCode();

        [$integer, $decimals] = array_pad(explode('.', (string) $money->abs()->getAmount()), 2, '');
        $number = strrev(implode('.', str_split(strrev($integer), 3))).($decimals !== '' ? ','.$decimals : '');

        return ($money->isNegative() ? '-' : '').$symbol.' '.$number;
    }

    public static function symbol(string $currency): string
    {
        return self::SYMBOLS[$currency] ?? $currency;
    }

    public static function formatMinor(int $minorAmount, string $currency = 'BRL'): string
    {
        return self::format(Money::ofMinor($minorAmount, $currency));
    }

    /**
     * Converte "1.234,56" (ou "1234,56", "-10", "R$ 5,00") em centavos.
     */
    public static function parseToMinor(string|int|float $input): int
    {
        if (is_int($input)) {
            return $input * 100;
        }

        if (is_float($input)) {
            return (int) round($input * 100);
        }

        $clean = preg_replace('/[^\d,.\-]/', '', $input) ?? '';
        $negative = str_starts_with($clean, '-');
        $clean = ltrim($clean, '-');

        if (! preg_match('/^\d{1,3}(\.\d{3})*(,\d{1,2})?$|^\d+(,\d{1,2})?$/', $clean)) {
            throw new InvalidArgumentException("Valor inválido: {$input}");
        }

        [$integer, $decimals] = array_pad(explode(',', str_replace('.', '', $clean)), 2, '0');

        $minor = ((int) $integer * 100) + (int) str_pad($decimals, 2, '0');

        return $negative ? -$minor : $minor;
    }
}
