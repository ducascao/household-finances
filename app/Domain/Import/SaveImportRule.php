<?php

namespace App\Domain\Import;

use App\Models\Category;
use App\Models\ImportRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SaveImportRule
{
    /**
     * @param  array<string, mixed>  $data  pattern, category_id, description, ignore, position
     */
    public function execute(int $householdId, array $data, ?ImportRule $rule = null): ImportRule
    {
        /** @var array{pattern: string, category_id?: int|null, description?: string|null, ignore?: bool|null, position?: int|null} $validated */
        $validated = Validator::make($data, [
            'pattern' => ['required', 'string', 'min:2', 'max:255'],
            'category_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:255'],
            'ignore' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
        ], attributes: [
            'pattern' => 'texto',
            'category_id' => 'categoria',
            'description' => 'descrição',
        ])->validate();

        $ignore = (bool) ($validated['ignore'] ?? false);
        $categoryId = $ignore ? null : ($validated['category_id'] ?? null);

        if (! $ignore && $categoryId === null) {
            throw ValidationException::withMessages(['category_id' => 'Escolha uma categoria ou marque "Ignorar".']);
        }

        if ($categoryId !== null && ! Category::withoutGlobalScopes()->where('household_id', $householdId)->whereKey($categoryId)->exists()) {
            throw ValidationException::withMessages(['category_id' => 'Categoria não encontrada.']);
        }

        $rule ??= new ImportRule;
        $rule->household_id = $householdId;
        $rule->fill([
            'pattern' => Text::clean($validated['pattern']),
            'category_id' => $categoryId,
            'description' => $ignore ? null : (($validated['description'] ?? null) ? Text::clean((string) $validated['description']) : null),
            'ignore' => $ignore,
            'position' => $validated['position']
                ?? ($rule->exists ? $rule->position : (int) ImportRule::withoutGlobalScopes()->where('household_id', $householdId)->max('position') + 1),
        ])->save();

        return $rule;
    }
}
