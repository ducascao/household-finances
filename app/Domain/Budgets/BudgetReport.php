<?php

namespace App\Domain\Budgets;

use App\Domain\Reports\MonthlySummary;
use App\Enums\BudgetStatus;
use App\Enums\CategoryType;
use App\Enums\TransactionStatus;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Orçado × realizado do mês, por competência e sem transferências, líquido de estornos.
 *
 * - A categoria principal soma o gasto das subcategorias.
 * - Orçado da principal: o valor dela; sem valor próprio, a soma dos orçados das subcategorias.
 * - Só aparecem categorias com orçamento ou gasto no mês.
 * - O realizado respeita a visibilidade de quem está logado (escopos globais).
 */
class BudgetReport
{
    /**
     * @return list<BudgetRow>
     */
    public function forMonth(int $householdId, Carbon $month): array
    {
        $month = $month->copy()->startOfMonth();
        $spending = $this->spending($month);
        $budgets = Budget::withoutGlobalScopes()
            ->where('household_id', $householdId)
            ->whereDate('month', $month)
            ->get()
            ->mapWithKeys(fn (Budget $budget): array => [$budget->category_id => $budget->amount->getMinorAmount()->toInt()]);

        $categories = Category::withoutGlobalScopes()
            ->where('household_id', $householdId)
            ->where('type', CategoryType::Expense->value)
            ->orderBy('name')
            ->get();

        $rows = [];

        foreach ($categories->whereNull('parent_id') as $root) {
            $children = [];

            foreach ($categories->where('parent_id', $root->id) as $child) {
                $row = $this->row($child, $budgets->get($child->id), $spending, []);

                if ($row->budget !== null || $row->committed() !== 0) {
                    $children[] = $row;
                }
            }

            $row = $this->row($root, $budgets->get($root->id), $spending, $children);

            if ($row->planned !== null || $row->committed() !== 0) {
                $rows[] = $row;
            }
        }

        usort($rows, fn (BudgetRow $a, BudgetRow $b): int => [$b->status()?->value === BudgetStatus::Exceeded->value, $b->percent() ?? -1]
            <=> [$a->status()?->value === BudgetStatus::Exceeded->value, $a->percent() ?? -1]);

        return $rows;
    }

    /**
     * Principais e subcategorias com a situação informada (ex.: estouradas).
     *
     * @return list<BudgetRow>
     */
    public function withStatus(int $householdId, Carbon $month, BudgetStatus $status): array
    {
        $matches = [];

        foreach ($this->forMonth($householdId, $month) as $row) {
            foreach ([$row, ...$row->children] as $candidate) {
                if ($candidate->status() === $status) {
                    $matches[] = $candidate;
                }
            }
        }

        return $matches;
    }

    /**
     * @param  Collection<int, array{paid: int, scheduled: int}>  $spending
     * @param  list<BudgetRow>  $children
     */
    private function row(Category $category, ?int $budget, Collection $spending, array $children): BudgetRow
    {
        $own = $spending->get($category->id, ['paid' => 0, 'scheduled' => 0]);
        $paid = $own['paid'] + array_sum(array_map(fn (BudgetRow $child): int => $child->paid, $children));
        $scheduled = $own['scheduled'] + array_sum(array_map(fn (BudgetRow $child): int => $child->scheduled, $children));

        $childrenBudget = array_sum(array_map(fn (BudgetRow $child): int => $child->budget ?? 0, $children));
        $planned = $budget ?? ($childrenBudget > 0 ? $childrenBudget : null);

        return new BudgetRow(
            category: $category,
            budget: $budget,
            planned: $planned,
            paid: $paid,
            scheduled: $scheduled,
            children: $children,
            childrenOverBudget: $budget !== null && $childrenBudget > $budget,
        );
    }

    /**
     * Gasto por categoria (pago e previsto), positivo.
     *
     * @return Collection<int, array{paid: int, scheduled: int}>
     */
    private function spending(Carbon $month): Collection
    {
        return Transaction::query()
            ->incomeAndExpense()
            ->where('transactions.currency', MonthlySummary::CURRENCY)
            ->whereDate('transactions.competence_date', $month)
            ->whereNotNull('transactions.category_id')
            ->toBase()
            ->selectRaw('transactions.category_id, transactions.status, -sum(transactions.amount) as total')
            ->groupBy('transactions.category_id', 'transactions.status')
            ->get()
            ->groupBy('category_id')
            ->map(fn (Collection $rows): array => [
                'paid' => (int) $rows->where('status', TransactionStatus::Paid->value)->sum('total'),
                'scheduled' => (int) $rows->where('status', TransactionStatus::Scheduled->value)->sum('total'),
            ]);
    }
}
