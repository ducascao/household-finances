<?php

use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Models\Category;
use App\Models\Household;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->household = Household::factory()->create();
    $this->user = User::factory()->withTwoFactor()->inHousehold($this->household)->create();
    $this->parent = Category::factory()->expense()->create(['household_id' => $this->household->id, 'name' => 'Moradia']);
    $this->child = Category::factory()->childOf($this->parent)->create(['name' => 'Aluguel']);
});

it('lista as categorias do lar e não as de outro lar', function () {
    $foreign = Category::factory()->create();
    $this->actingAs($this->user);

    Livewire::test(ListCategories::class)
        ->assertCanSeeTableRecords([$this->parent, $this->child])
        ->assertCanNotSeeTableRecords([$foreign])
        ->searchTable('Alug')
        ->assertCanSeeTableRecords([$this->child])
        ->assertCanNotSeeTableRecords([$this->parent]);
});

it('mostra o erro de terceiro nível no campo categoria pai', function () {
    $this->actingAs($this->user);

    Livewire::test(CreateCategory::class)
        ->fillForm(['name' => 'Neta', 'type' => 'expense', 'parent_id' => $this->child->id])
        ->call('create')
        ->assertHasFormErrors(['parent_id']);

    expect(Category::where('name', 'Neta')->exists())->toBeFalse();
});

it('cria subcategoria pelo formulário', function () {
    $this->actingAs($this->user);

    Livewire::test(CreateCategory::class)
        ->fillForm(['name' => 'Luz', 'type' => 'expense', 'parent_id' => $this->parent->id])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Category::where('name', 'Luz')->sole())
        ->parent_id->toBe($this->parent->id)
        ->household_id->toBe($this->household->id);
});
