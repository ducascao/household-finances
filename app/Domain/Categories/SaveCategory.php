<?php

namespace App\Domain\Categories;

use App\Enums\CategoryType;
use App\Models\Category;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveCategory
{
    /**
     * Cria ou atualiza a categoria respeitando o limite de 2 níveis.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data, ?Category $category = null, ?int $householdId = null): Category
    {
        $data['type'] = $data['type'] instanceof CategoryType ? $data['type']->value : ($data['type'] ?? null);

        /** @var array{name: string, type: string, parent_id: int|null, color: string|null} $validated */
        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(CategoryType::class)],
            'parent_id' => ['nullable', 'integer'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], attributes: [
            'name' => 'nome',
            'type' => 'tipo',
            'parent_id' => 'categoria pai',
            'color' => 'cor',
        ])->validate();

        $validated['parent_id'] ??= null;
        $validated['color'] ??= null;

        $category ??= new Category;

        if ($householdId !== null && ! $category->exists) {
            $category->household_id = $householdId;
        }

        if ($validated['parent_id'] !== null) {
            $this->ensureValidParent($category, (int) $validated['parent_id'], $validated['type'], $householdId ?? $category->household_id);
        }

        if ($category->exists && $category->type->value !== $validated['type'] && $category->children()->exists()) {
            throw ValidationException::withMessages([
                'type' => 'Não é possível mudar o tipo de uma categoria que tem subcategorias.',
            ]);
        }

        $category->fill($validated)->save();

        return $category;
    }

    private function ensureValidParent(Category $category, int $parentId, string $type, ?int $householdId): void
    {
        $parent = Category::query()
            ->when($householdId !== null, fn ($query) => $query->where('household_id', $householdId))
            ->find($parentId);

        $error = match (true) {
            $parent === null => 'Categoria pai não encontrada.',
            $category->exists && $parent->id === $category->id => 'A categoria não pode ser pai dela mesma.',
            $parent->parent_id !== null => 'Só são permitidos 2 níveis: escolha uma categoria principal como pai.',
            $category->exists && $category->children()->exists() => 'Esta categoria tem subcategorias e não pode virar subcategoria.',
            $parent->type->value !== $type => 'A subcategoria precisa ser do mesmo tipo da categoria pai.',
            default => null,
        };

        if ($error !== null) {
            throw ValidationException::withMessages(['parent_id' => $error]);
        }
    }
}
