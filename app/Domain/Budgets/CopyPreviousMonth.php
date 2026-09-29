<?php

namespace App\Domain\Budgets;

use App\Models\Budget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CopyPreviousMonth
{
    /**
     * Copia os orçamentos do mês anterior para as categorias que ainda não têm valor no mês. Não sobrescreve.
     *
     * @return int quantidade copiada
     */
    public function execute(int $householdId, Carbon $month): int
    {
        $target = $month->copy()->startOfMonth();
        $previous = $target->copy()->subMonthNoOverflow();

        $existing = Budget::withoutGlobalScopes()
            ->where('household_id', $householdId)
            ->whereDate('month', $target)
            ->pluck('category_id')
            ->flip();

        return DB::transaction(function () use ($householdId, $previous, $target, $existing): int {
            $copied = 0;

            Budget::withoutGlobalScopes()
                ->where('household_id', $householdId)
                ->whereDate('month', $previous)
                ->get()
                ->reject(fn (Budget $budget): bool => $existing->has($budget->category_id))
                ->each(function (Budget $budget) use ($householdId, $target, &$copied): void {
                    $copy = new Budget(['category_id' => $budget->category_id, 'month' => $target, 'amount' => $budget->amount]);
                    $copy->household_id = $householdId;
                    $copy->save();
                    $copied++;
                });

            return $copied;
        });
    }
}
