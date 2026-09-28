<?php

namespace Database\Factories;

use App\Enums\CategoryType;
use App\Models\Category;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'name' => ucfirst(fake()->unique()->word()),
            'type' => CategoryType::Expense,
            'color' => fake()->hexColor(),
        ];
    }

    public function income(): static
    {
        return $this->state(fn (array $attributes) => ['type' => CategoryType::Income]);
    }

    public function expense(): static
    {
        return $this->state(fn (array $attributes) => ['type' => CategoryType::Expense]);
    }

    public function childOf(Category $parent): static
    {
        return $this->state(fn (array $attributes) => [
            'household_id' => $parent->household_id,
            'parent_id' => $parent->id,
            'type' => $parent->type,
        ]);
    }
}
