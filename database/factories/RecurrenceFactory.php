<?php

namespace Database\Factories;

use App\Enums\RecurrenceFrequency;
use App\Models\Account;
use App\Models\Category;
use App\Models\Recurrence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Para regras de sinal e próxima data use a ação CreateRecurrence; a factory grava os valores como vierem.
 *
 * @extends Factory<Recurrence>
 */
class RecurrenceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'household_id' => fn (array $attributes) => Account::withoutGlobalScopes()->findOrFail($attributes['account_id'])->household_id,
            'currency' => fn (array $attributes) => Account::withoutGlobalScopes()->findOrFail($attributes['account_id'])->currency,
            'paid_by' => fn (array $attributes) => Account::withoutGlobalScopes()->findOrFail($attributes['account_id'])->owner_id,
            'category_id' => fn (array $attributes) => Category::factory()->expense()->create([
                'household_id' => $attributes['household_id'],
            ])->id,
            'amount' => -fake()->numberBetween(5000, 300000),
            'description' => ucfirst(fake()->words(2, true)),
            'tags' => [],
            'frequency' => RecurrenceFrequency::Monthly,
            'interval_months' => null,
            'day_of_month' => 10,
            'start_date' => today(),
            'next_date' => today(),
            'end_date' => null,
        ];
    }

    public function forAccount(Account $account): static
    {
        return $this->state(fn (array $attributes) => ['account_id' => $account->id]);
    }
}
