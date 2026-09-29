<?php

namespace App\Domain\Investments;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;

/**
 * Posição de um ativo: quantidade, preço médio (com taxas) e custo total, em decimal de 8 casas.
 */
final readonly class Position
{
    public function __construct(
        public BigDecimal $quantity,
        public BigDecimal $averagePrice,
        public BigDecimal $totalCost,
    ) {}

    public static function empty(): self
    {
        $zero = BigDecimal::zero()->toScale(8);

        return new self($zero, $zero, $zero);
    }

    public function isOpen(): bool
    {
        return $this->quantity->isPositive();
    }

    public function totalCostMoney(string $currency = 'BRL'): Money
    {
        return Money::of($this->totalCost->toScale(2, RoundingMode::HalfUp), $currency);
    }

    /**
     * Valor de mercado pela cotação informada.
     */
    public function marketValue(BigDecimal $price, string $currency = 'BRL'): Money
    {
        return Money::of($this->quantity->multipliedBy($price)->toScale(2, RoundingMode::HalfUp), $currency);
    }
}
