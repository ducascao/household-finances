<?php

namespace App\Domain\Investments;

use App\Models\Asset;
use App\Models\AssetPrice;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;

/**
 * Posição de um ativo com a última cotação disponível. Sem cotação, o valor de mercado usa o preço médio.
 */
final readonly class PortfolioRow
{
    public function __construct(
        public Asset $asset,
        public Position $position,
        public ?AssetPrice $lastPrice,
    ) {}

    public function price(): BigDecimal
    {
        return $this->lastPrice !== null ? BigDecimal::of($this->lastPrice->price) : $this->position->averagePrice;
    }

    public function marketValue(): Money
    {
        return $this->position->marketValue($this->price(), $this->asset->currency);
    }

    public function cost(): Money
    {
        return $this->position->totalCostMoney($this->asset->currency);
    }

    public function result(): Money
    {
        return $this->marketValue()->minus($this->cost());
    }

    public function resultPercent(): ?float
    {
        if (! $this->position->totalCost->isPositive()) {
            return null;
        }

        return BigDecimal::of((string) $this->result()->getAmount())
            ->dividedBy($this->position->totalCost, 6, RoundingMode::HalfUp)
            ->multipliedBy(100)
            ->toScale(2, RoundingMode::HalfUp)
            ->toFloat();
    }

    /**
     * Cotação com mais de 7 dias (ou inexistente) merece atenção.
     */
    public function isPriceStale(): bool
    {
        return $this->lastPrice === null || $this->lastPrice->date->lt(today()->subDays(7));
    }
}
