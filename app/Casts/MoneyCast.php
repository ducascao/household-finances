<?php

namespace App\Casts;

use Brick\Money\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Valor monetário gravado como inteiro em centavos (unidade mínima da moeda).
 * A moeda vem da coluna indicada no parâmetro do cast (padrão: currency).
 *
 * @implements CastsAttributes<Money, Money|int>
 */
class MoneyCast implements CastsAttributes
{
    public function __construct(
        private readonly string $currencyAttribute = 'currency',
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        return Money::ofMinor((int) $value, $this->currency($attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if ($value->getCurrency()->getCurrencyCode() !== $this->currency($attributes)) {
            throw new InvalidArgumentException("Moeda {$value->getCurrency()} diferente da moeda do registro.");
        }

        return $value->getMinorAmount()->toInt();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function currency(array $attributes): string
    {
        $currency = $attributes[$this->currencyAttribute] ?? null;

        return is_string($currency) && $currency !== '' ? $currency : 'BRL';
    }
}
