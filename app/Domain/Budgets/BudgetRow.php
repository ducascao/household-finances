<?php

namespace App\Domain\Budgets;

use App\Enums\BudgetStatus;
use App\Models\Category;

/**
 * Uma linha do orçado × realizado. Valores em centavos, despesas positivas.
 * "Comprometido" = realizado (pago) + previsto; situação e percentual usam o comprometido.
 */
final class BudgetRow
{
    /**
     * @param  list<BudgetRow>  $children
     */
    public function __construct(
        public readonly Category $category,
        public readonly ?int $budget,
        public readonly ?int $planned,
        public readonly int $paid,
        public readonly int $scheduled,
        public readonly array $children = [],
        public readonly bool $childrenOverBudget = false,
    ) {}

    public function committed(): int
    {
        return $this->paid + $this->scheduled;
    }

    public function available(): ?int
    {
        return $this->planned === null ? null : $this->planned - $this->committed();
    }

    public function percent(): ?float
    {
        if ($this->planned === null || $this->planned === 0) {
            return null;
        }

        return round($this->committed() / $this->planned * 100, 1);
    }

    public function status(): ?BudgetStatus
    {
        $percent = $this->percent();

        return $percent === null ? null : BudgetStatus::fromPercent($percent);
    }

    public function isRoot(): bool
    {
        return $this->category->parent_id === null;
    }
}
