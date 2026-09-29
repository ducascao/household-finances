<?php

namespace App\Domain\Investments;

use App\Enums\AssetOperationType;
use App\Models\AssetOperation;
use App\Models\ManualValuation;
use Illuminate\Support\Carbon;

/**
 * Valor de renda fixa/previdência pelos saldos informados e pelos aportes/resgates.
 */
class ValuationCalculator
{
    /**
     * @param  iterable<AssetOperation>  $operations
     * @param  iterable<ManualValuation>  $valuations
     */
    public function at(iterable $operations, iterable $valuations, ?Carbon $date = null): ValuationPosition
    {
        $date = ($date ?? today())->copy()->startOfDay();

        $flows = collect($operations)
            ->filter(fn (AssetOperation $operation): bool => $operation->type->isCashFlow() && $operation->date->lte($date));

        $last = collect($valuations)
            ->filter(fn (ManualValuation $valuation): bool => $valuation->date->lte($date))
            ->sortBy(fn (ManualValuation $valuation): string => $valuation->date->toDateString())
            ->last();

        $invested = (int) $flows->sum(fn (AssetOperation $operation): int => self::signed($operation));

        if ($last === null) {
            return new ValuationPosition($invested, max(0, $invested), null, $flows->isNotEmpty());
        }

        $after = $flows->filter(fn (AssetOperation $operation): bool => $operation->date->gt($last->date));

        return new ValuationPosition(
            $invested,
            max(0, $last->balance + (int) $after->sum(fn (AssetOperation $operation): int => self::signed($operation))),
            $last,
            $after->isNotEmpty(),
        );
    }

    /**
     * Aporte soma, resgate subtrai (centavos).
     */
    public static function signed(AssetOperation $operation): int
    {
        return $operation->type === AssetOperationType::Contribution ? (int) $operation->amount : -(int) $operation->amount;
    }
}
