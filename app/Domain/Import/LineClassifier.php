<?php

namespace App\Domain\Import;

use App\Enums\CategoryType;
use App\Enums\ImportLineAction;
use App\Models\Category;
use App\Models\ImportLine;
use App\Models\ImportRule;

/**
 * Aplica regras a uma linha nova: categoria e descrição amigável, ou ignorar.
 * Sem regra, usa "Outras despesas"/"Outras receitas" conforme o sinal.
 */
class LineClassifier
{
    private RuleMatcher $matcher;

    /** @var array<string, int|null> */
    private array $defaults;

    public function __construct(private readonly int $householdId)
    {
        $this->matcher = new RuleMatcher($householdId);
        $this->defaults = [
            CategoryType::Expense->value => $this->categoryId('Outras despesas', CategoryType::Expense),
            CategoryType::Income->value => $this->categoryId('Outras receitas', CategoryType::Income),
        ];
    }

    public function classify(ImportLine $line): void
    {
        $rule = $this->matcher->match($line->description);
        $line->import_rule_id = $rule?->id;

        if ($rule?->ignore) {
            $line->action = ImportLineAction::Skip;

            return;
        }

        $line->action = ImportLineAction::Import;
        $line->category_id = $rule->category_id ?? $this->defaultCategory($line);
        $line->final_description = $rule?->description ?: $line->description;
    }

    public function matches(ImportRule $rule, ImportLine $line): bool
    {
        return str_contains(Text::normalize($line->description), Text::normalize($rule->pattern));
    }

    private function defaultCategory(ImportLine $line): ?int
    {
        return $this->defaults[$line->amount->isNegative() ? CategoryType::Expense->value : CategoryType::Income->value];
    }

    private function categoryId(string $name, CategoryType $type): ?int
    {
        $id = Category::withoutGlobalScopes()
            ->where('household_id', $this->householdId)
            ->where('type', $type->value)
            ->where('name', $name)
            ->value('id');

        return $id !== null ? (int) $id : null;
    }
}
