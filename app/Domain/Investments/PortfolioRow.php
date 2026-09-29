<?php

namespace App\Domain\Investments;

use App\Models\Asset;
use App\Models\AssetPrice;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;

/**
 * Linha da carteira. Ativos da B3: posição em cotas × última cotação (sem cotação, o PM).
 * Renda fixa/previdência: valor pelo saldo informado (ValuationPosition).
 */
final readonly class PortfolioRow
{
    public function __construct(
        public Asset $asset,
        public Position $position,
        public ?AssetPrice $lastPrice,
        public ?ValuationPosition $valuation = null,
    ) {}

    public function isValuedByBalance(): bool
    {
        return $this->valuation !== null;
    }

    public function price(): BigDecimal
    {
        return $this->lastPrice !== null ? BigDecimal::of($this->lastPrice->price) : $this->position->averagePrice;
    }

    public function marketValue(): Money
    {
        if ($this->valuation !== null) {
            return Money::ofMinor($this->valuation->value, $this->asset->currency);
        }

        return $this->position->marketValue($this->price(), $this->asset->currency);
    }

    public function cost(): Money
    {
        if ($this->valuation !== null) {
            return Money::ofMinor($this->valuation->invested, $this->asset->currency);
        }

        return $this->position->totalCostMoney($this->asset->currency);
    }

    public function result(): Money
    {
        return $this->marketValue()->minus($this->cost());
    }

    public function resultPercent(): ?float
    {
        $cost = BigDecimal::of((string) $this->cost()->getAmount());

        if (! $cost->isPositive()) {
            return null;
        }

        return BigDecimal::of((string) $this->result()->getAmount())
            ->dividedBy($cost, 6, RoundingMode::HalfUp)
            ->multipliedBy(100)
            ->toScale(2, RoundingMode::HalfUp)
            ->toFloat();
    }

    /**
     * Cotação (B3) com mais de 7 dias, ou saldo informado (renda fixa/previdência) com mais de 30 dias.
     */
    public function isPriceStale(): bool
    {
        if ($this->valuation !== null) {
            return $this->valuation->lastValuation === null || $this->valuation->lastValuation->date->lt(today()->subDays(30));
        }

        return $this->lastPrice === null || $this->lastPrice->date->lt(today()->subDays(7));
    }
}
