<?php

namespace App\Domain\Import;

use App\Models\ImportRule;
use Illuminate\Support\Collection;

/**
 * Primeira regra (pela posição) cujo texto está contido na descrição, sem diferenciar maiúsculas e acentos.
 */
class RuleMatcher
{
    /** @var Collection<int, ImportRule> */
    private Collection $rules;

    public function __construct(int $householdId)
    {
        $this->rules = ImportRule::withoutGlobalScopes()
            ->where('household_id', $householdId)
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    public function match(string $description): ?ImportRule
    {
        $normalized = Text::normalize($description);

        return $this->rules->first(fn (ImportRule $rule): bool => str_contains($normalized, Text::normalize($rule->pattern)));
    }
}
