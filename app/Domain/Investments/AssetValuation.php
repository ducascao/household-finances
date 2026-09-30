<?php

namespace App\Domain\Investments;

use App\Models\Asset;
use App\Models\AssetOperation;
use App\Models\ManualValuation;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Valor de um ativo numa data, na moeda dele (centavos). Mesmo critério para Rentabilidade e Patrimônio:
 * - B3/exterior: posição até a data × última cotação até a data (sem cotação, o preço médio);
 * - renda fixa/previdência: último saldo informado até a data + aportes − resgates depois dele.
 */
class AssetValuation
{
    public function __construct(
        private readonly PositionCalculator $positions,
        private readonly ValuationCalculator $valuations,
    ) {}

    /**
     * @param  Collection<int, AssetOperation>  $operations  todas as operações do ativo
     * @param  Collection<int, ManualValuation>  $valuations  saldos informados do ativo
     * @param  string|null  $price  última cotação até a data
     */
    public function valueAt(Asset $asset, Collection $operations, Collection $valuations, ?string $price, Carbon $date): int
    {
        if ($asset->type->isValuedByBalance()) {
            return $this->valuations->at($operations, $valuations, $date)->value;
        }

        $position = $this->positions->calculate($operations->filter(fn (AssetOperation $op): bool => $op->date->lte($date)));

        if (! $position->isOpen()) {
            return 0;
        }

        return $position->marketValue($price !== null ? BigDecimal::of($price) : $position->averagePrice)->getMinorAmount()->toInt();
    }
}
