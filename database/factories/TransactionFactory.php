<?php

namespace Database\Factories;

use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Para regras de sinal e competência use a ação CreateTransaction; a factory grava os valores como vierem.
 *
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = fake()->dateTimeBetween('-2 months');

        return [
            'account_id' => Account::factory(),
            'household_id' => fn (array $attributes) => Account::withoutGlobalScopes()->findOrFail($attributes['account_id'])->household_id,
            'currency' => fn (array $attributes) => Account::withoutGlobalScopes()->findOrFail($attributes['account_id'])->currency,
            'paid_by' => fn (array $attributes) => Account::withoutGlobalScopes()->findOrFail($attributes['account_id'])->owner_id,
            'category_id' => fn (array $attributes) => Category::factory()->expense()->create([
                'household_id' => $attributes['household_id'],
            ])->id,
            'amount' => -fake()->numberBetween(1000, 50000),
            'date' => $date,
            'competence_date' => (clone $date)->modify('first day of this month'),
            'description' => ucfirst(fake()->words(3, true)),
            'tags' => [],
        ];
    }

    public function forAccount(Account $account): static
    {
        return $this->state(fn (array $attributes) => ['account_id' => $account->id]);
    }

    /**
     * Valor em centavos com o sinal dado pelo tipo da categoria.
     */
    public function withCategory(Category $category, int $absoluteAmount): static
    {
        return $this->state(fn (array $attributes) => [
            'category_id' => $category->id,
            'amount' => $category->type === CategoryType::Expense ? -$absoluteAmount : $absoluteAmount,
        ]);
    }
}
