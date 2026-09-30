<?php

namespace App\Domain\Goods;

use App\Domain\Debts\DebtSummary;
use App\Models\Debt;
use App\Models\Good;
use App\Models\GoodValuation;
use Illuminate\Support\Carbon;

/**
 * Valor de um bem numa data (centavos): zero antes da compra e a partir da venda; entre elas, a última
 * avaliação até a data ou, sem avaliação, o valor de aquisição.
 */
class GoodValue
{
    public const STALE_DAYS = 180;

    public static function at(Good $good, Carbon $date): int
    {
        $date = $date->copy()->startOfDay();

        if ($date->lt($good->acquisition_date) || ($good->sale_date !== null && $date->gte($good->sale_date))) {
            return 0;
        }

        $valuation = self::lastValuation($good, $date);

        return $valuation !== null ? $valuation->value : $good->acquisition_value;
    }

    public static function lastValuation(Good $good, ?Carbon $date = null): ?GoodValuation
    {
        return GoodValuation::withoutGlobalScopes()
            ->where('good_id', $good->id)
            ->whereDate('date', '<=', $date ?? today())
            ->orderByDesc('date')
            ->first();
    }

    /**
     * Última referência de valor (avaliação ou aquisição) com mais de 6 meses, para bens que ainda são seus.
     */
    public static function isStale(Good $good): bool
    {
        if ($good->sale_date !== null) {
            return false;
        }

        $reference = self::lastValuation($good)->date ?? $good->acquisition_date;

        return $reference->lt(today()->subDays(self::STALE_DAYS));
    }

    /**
     * Saldo devedor atual da dívida vinculada (0 sem dívida).
     */
    public static function linkedDebt(Good $good): int
    {
        if ($good->debt_id === null) {
            return 0;
        }

        $debt = Debt::withoutGlobalScopes()->find($good->debt_id);

        return $debt !== null ? (new DebtSummary($debt))->outstanding() : 0;
    }

    /**
     * Valor líquido hoje: valor do bem − saldo da dívida vinculada.
     */
    public static function net(Good $good): int
    {
        return self::at($good, today()) - self::linkedDebt($good);
    }
}
