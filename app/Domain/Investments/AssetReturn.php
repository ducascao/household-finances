<?php

namespace App\Domain\Investments;

use App\Models\Asset;

/**
 * Rentabilidade de um ativo (ou da carteira, sem ativo) num período. Valores em centavos.
 *
 * - valorização = valor final − valor inicial − compras + vendas
 * - resultado = valorização + proventos
 * - % (Dietz modificado) = resultado ÷ (valor inicial + fluxos ponderados pelo tempo aplicado no período)
 */
final class AssetReturn
{
    public function __construct(
        public readonly ?Asset $asset,
        public int $startValue = 0,
        public int $buys = 0,
        public int $sells = 0,
        public int $incomes = 0,
        public int $endValue = 0,
        public int $realized = 0,
        public float $weightedFlows = 0.0,
    ) {}

    public function appreciation(): int
    {
        return $this->endValue - $this->startValue - $this->buys + $this->sells;
    }

    public function result(): int
    {
        return $this->appreciation() + $this->incomes;
    }

    public function percent(): ?float
    {
        $base = $this->startValue + $this->weightedFlows;

        return $base > 0 ? round($this->result() / $base * 100, 2) : null;
    }

    public function isEmpty(): bool
    {
        return $this->startValue === 0 && $this->endValue === 0 && $this->buys === 0 && $this->sells === 0 && $this->incomes === 0;
    }

    public function add(self $other): void
    {
        $this->startValue += $other->startValue;
        $this->buys += $other->buys;
        $this->sells += $other->sells;
        $this->incomes += $other->incomes;
        $this->endValue += $other->endValue;
        $this->realized += $other->realized;
        $this->weightedFlows += $other->weightedFlows;
    }
}
