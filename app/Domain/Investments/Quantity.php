<?php

namespace App\Domain\Investments;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Quantidades e preços unitários (8 casas) no padrão brasileiro: "1.234,5" ↔ "1234.50000000".
 */
class Quantity
{
    public static function parse(string|int|float|null $input): ?string
    {
        if ($input === null || $input === '') {
            return null;
        }

        if (is_int($input) || is_float($input)) {
            return BigDecimal::of((string) $input)->toScale(8, RoundingMode::HalfUp)->__toString();
        }

        $clean = trim($input);

        // Com vírgula: padrão brasileiro (ponto = milhar). Sem vírgula: ponto é decimal.
        if (str_contains($clean, ',')) {
            $clean = str_replace(['.', ','], ['', '.'], $clean);
        }

        try {
            return BigDecimal::of($clean)->toScale(8, RoundingMode::HalfUp)->__toString();
        } catch (MathException) {
            throw new InvalidArgumentException("Número inválido: {$input}");
        }
    }

    /**
     * "1234.50000000" → "1.234,5"; mostra até 8 casas, sem zeros à direita.
     */
    public static function format(string $value, int $minDecimals = 0): string
    {
        $decimal = BigDecimal::of($value === '' ? '0' : $value)->toScale(8, RoundingMode::HalfUp);
        [$integer, $fraction] = explode('.', (string) $decimal->abs()) + [1 => ''];
        $fraction = rtrim($fraction, '0');
        $fraction = str_pad($fraction, $minDecimals, '0');

        $integer = strrev(implode('.', str_split(strrev($integer), 3)));

        return ($decimal->isNegative() ? '-' : '').$integer.($fraction !== '' ? ','.$fraction : '');
    }
}
