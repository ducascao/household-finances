<?php

use App\Domain\Categories\DeleteCategory;
use App\Domain\Categories\SaveCategory;
use App\Domain\Household\CreateHousehold;
use App\Enums\CategoryType;
use App\Models\Category;
use App\Models\Household;
use Illuminate\Validation\ValidationException;

it('cria as categorias padrão junto com o lar', function () {
    $household = app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');

    $categories = Category::where('household_id', $household->id)->get();
    $moradia = $categories->firstWhere('name', 'Moradia');

    expect($categories->where('type', CategoryType::Income)->count())->toBeGreaterThan(0)
        ->and($moradia->type)->toBe(CategoryType::Expense)
        ->and($categories->where('parent_id', $moradia->id)->pluck('name'))->toContain('Aluguel', 'Luz')
        ->and($categories->whereNotNull('parent_id')->every(
            fn (Category $c) => $categories->find($c->parent_id)->parent_id === null
        ))->toBeTrue();
});

it('cria subcategoria de uma categoria principal', function () {
    $parent = Category::factory()->expense()->create();

    $child = app(SaveCategory::class)->execute(
        ['name' => 'Mercado', 'type' => 'expense', 'parent_id' => $parent->id],
        householdId: $parent->household_id,
    );

    expect($child->parent_id)->toBe($parent->id)
        ->and($child->household_id)->toBe($parent->household_id);
});

it('não permite terceiro nível de categoria', function () {
    $parent = Category::factory()->expense()->create();
    $child = Category::factory()->childOf($parent)->create();

    app(SaveCategory::class)->execute(
        ['name' => 'Neta', 'type' => 'expense', 'parent_id' => $child->id],
        householdId: $parent->household_id,
    );
})->throws(ValidationException::class, 'Só são permitidos 2 níveis');

it('não permite que categoria com filhas vire subcategoria', function () {
    $parent = Category::factory()->expense()->create();
    Category::factory()->childOf($parent)->create();
    $other = Category::factory()->expense()->create(['household_id' => $parent->household_id]);

    app(SaveCategory::class)->execute(
        ['name' => $parent->name, 'type' => 'expense', 'parent_id' => $other->id],
        $parent,
    );
})->throws(ValidationException::class, 'tem subcategorias');

it('exige o mesmo tipo da categoria pai', function () {
    $parent = Category::factory()->income()->create();

    app(SaveCategory::class)->execute(
        ['name' => 'Mercado', 'type' => 'expense', 'parent_id' => $parent->id],
        householdId: $parent->household_id,
    );
})->throws(ValidationException::class, 'mesmo tipo');

it('não aceita pai de outro lar', function () {
    $foreign = Category::factory()->expense()->create();
    $household = Household::factory()->create();

    app(SaveCategory::class)->execute(
        ['name' => 'Mercado', 'type' => 'expense', 'parent_id' => $foreign->id],
        householdId: $household->id,
    );
})->throws(ValidationException::class, 'Categoria pai não encontrada.');

it('não exclui categoria com subcategorias', function () {
    $parent = Category::factory()->expense()->create();
    Category::factory()->childOf($parent)->create();

    app(DeleteCategory::class)->execute($parent);
})->throws(ValidationException::class);
