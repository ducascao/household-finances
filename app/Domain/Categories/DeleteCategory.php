<?php

namespace App\Domain\Categories;

use App\Models\Category;
use Illuminate\Validation\ValidationException;

class DeleteCategory
{
    public function execute(Category $category): void
    {
        if ($category->children()->exists()) {
            throw ValidationException::withMessages([
                'category' => 'Exclua ou mova as subcategorias antes de excluir esta categoria.',
            ]);
        }

        if ($category->transactions()->withoutGlobalScopes()->exists()) {
            throw ValidationException::withMessages([
                'category' => 'A categoria tem lançamentos: mova-os para outra categoria antes de excluir.',
            ]);
        }

        $category->delete();
    }
}
