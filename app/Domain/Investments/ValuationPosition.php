<?php

namespace App\Domain\Investments;

use App\Models\ManualValuation;

/**
 * Posição de renda fixa/previdência numa data. Valores em centavos.
 *
 * - investido = aportes − resgates
 * - valor = último saldo informado + aportes − resgates feitos depois dele ("estimado" nesse caso)
 * - rendimento = valor − investido
 */
final readonly class ValuationPosition
{
    public function __construct(
        public int $invested,
        public int $value,
        public ?ManualValuation $lastValuation,
        public bool $estimated,
    ) {}

    public function yield(): int
    {
        return $this->value - $this->invested;
    }

    public function isOpen(): bool
    {
        return $this->value > 0 || $this->invested > 0;
    }
}
