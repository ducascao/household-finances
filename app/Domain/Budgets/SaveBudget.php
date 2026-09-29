<?php

namespace App\Domain\Budgets;

use App\Domain\Reports\MonthlySummary;
use App\Enums\CategoryType;
use App\Models\Budget;
use App\Models\Category;
use Brick\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class SaveBudget
{
    /**
     * Define o orçado da categoria no mês. Valor nulo ou zero remove o orçamento.
     */
    public function execute(int $householdId, int $categoryId, Carbon $month, ?int $amount): ?Budget
    {
        $category = Category::withoutGlobalScopes()->where('household_id', $householdId)->find($categoryId);

        if ($category === null) {
            throw ValidationException::withMessages(['category_id' => 'Categoria não encontrada.']);
        }

        if ($category->type !== CategoryType::Expense) {
            throw ValidationException::withMessages(['category_id' => 'Orçamento só para categorias de despesa.']);
        }

        if ($amount !== null && $amount < 0) {
            throw ValidationException::withMessages(['amount' => 'O valor orçado não pode ser negativo.']);
        }

        $query = Budget::withoutGlobalScopes()
            ->where('category_id', $category->id)
            ->whereDate('month', $month->copy()->startOfMonth());

        if ($amount === null || $amount === 0) {
            $query->delete();

            return null;
        }

        $budget = $query->first() ?? new Budget(['category_id' => $category->id, 'month' => $month->copy()->startOfMonth()]);
        $budget->household_id = $householdId;
        $budget->amount = Money::ofMinor($amount, MonthlySummary::CURRENCY);
        $budget->save();

        return $budget;
    }
}
