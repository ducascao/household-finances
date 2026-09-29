<?php

namespace App\Domain\Investments;

use App\Models\AssetOperation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Resultado de uma venda: valor líquido recebido − custo (quantidade × PM na data).
 */
final readonly class RealizedSale
{
    public function __construct(
        public AssetOperation $operation,
        public BigDecimal $proceeds,
        public BigDecimal $costBasis,
    ) {}

    public function result(): BigDecimal
    {
        return $this->proceeds->minus($this->costBasis);
    }

    /**
     * Resultado em centavos.
     */
    public function resultMinor(): int
    {
        return $this->result()->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();
    }
}
