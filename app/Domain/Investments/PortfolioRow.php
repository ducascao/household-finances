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
 *
 * Em reais: o custo vem de cada operação convertida pelo câmbio da data (costBrlMinor) e o valor de
 * mercado pelo câmbio atual (rateNow). Resultado em reais = variação do ativo + variação cambial.
 * Para ativos em BRL, os valores em reais são os próprios (câmbio 1). Sem câmbio, ficam null.
 */
final readonly class PortfolioRow
{
    public function __construct(
        public Asset $asset,
        public Position $position,
        public ?AssetPrice $lastPrice,
        public ?ValuationPosition $valuation = null,
        public ?int $costBrlMinor = null,
        public ?BigDecimal $rateNow = null,
    ) {}

    public function isForeign(): bool
    {
        return $this->asset->currency !== 'BRL';
    }

    public function hasBrlValues(): bool
    {
        return ! $this->isForeign() || ($this->rateNow !== null && $this->costBrlMinor !== null);
    }

    public function marketValueBrl(): ?Money
    {
        if (! $this->isForeign()) {
            return $this->marketValue();
        }

        return $this->rateNow === null ? null
            : Money::of($this->marketValue()->getAmount()->multipliedBy($this->rateNow)->toScale(2, RoundingMode::HalfUp), 'BRL');
    }

    public function costBrl(): ?Money
    {
        if (! $this->isForeign()) {
            return $this->cost();
        }

        return $this->costBrlMinor === null ? null : Money::ofMinor($this->costBrlMinor, 'BRL');
    }

    public function resultBrl(): ?Money
    {
        $market = $this->marketValueBrl();
        $cost = $this->costBrl();

        return $market === null || $cost === null ? null : $market->minus($cost);
    }

    /**
     * Variação do ativo em reais: (valor de mercado − custo, na moeda) × câmbio atual.
     */
    public function assetEffectBrl(): ?Money
    {
        if ($this->rateNow === null && $this->isForeign()) {
            return null;
        }

        $rate = $this->isForeign() ? $this->rateNow : BigDecimal::one();

        return Money::of($this->result()->getAmount()->multipliedBy((string) $rate)->toScale(2, RoundingMode::HalfUp), 'BRL');
    }

    /**
     * Variação cambial em reais: custo na moeda × (câmbio atual − câmbio médio de compra).
     */
    public function fxEffectBrl(): ?Money
    {
        $result = $this->resultBrl();
        $asset = $this->assetEffectBrl();

        return $result === null || $asset === null ? null : $result->minus($asset);
    }

    /**
     * Câmbio médio de compra (reais por unidade): custo em reais ÷ custo na moeda.
     */
    public function averageRate(): ?BigDecimal
    {
        $cost = $this->cost()->getAmount();

        if ($this->costBrlMinor === null || ! $cost->isPositive()) {
            return null;
        }

        return BigDecimal::ofUnscaledValue($this->costBrlMinor, 2)->dividedBy($cost, 4, RoundingMode::HalfUp);
    }

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
