<?php

namespace App\Domain\Investments;

use App\Models\AssetOperation;
use Brick\Math\BigDecimal;
use RuntimeException;

class InvalidPosition extends RuntimeException
{
    public static function oversold(AssetOperation $operation, BigDecimal $available): self
    {
        return new self(sprintf(
            'A venda de %s em %s é maior que a posição na data (%s).',
            Quantity::format((string) $operation->quantity),
            $operation->date->format('d/m/Y'),
            Quantity::format((string) $available),
        ));
    }
}
