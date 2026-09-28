<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Enums\AccountVisibility;
use App\Models\Account;
use App\Models\Household;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'owner_id' => User::factory(),
            'name' => fake()->randomElement(['Nubank', 'Itaú', 'Bradesco', 'Inter', 'Caixa']).' '.fake()->randomNumber(3),
            'type' => AccountType::Checking,
            'visibility' => AccountVisibility::Private,
            'currency' => 'BRL',
            'initial_balance' => 0,
        ];
    }

    public function ownedBy(User $owner): static
    {
        return $this->state(fn (array $attributes) => [
            'household_id' => $owner->current_household_id,
            'owner_id' => $owner->id,
        ]);
    }

    public function shared(): static
    {
        return $this->state(fn (array $attributes) => ['visibility' => AccountVisibility::Shared]);
    }

    public function private(): static
    {
        return $this->state(fn (array $attributes) => ['visibility' => AccountVisibility::Private]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => ['archived_at' => now()]);
    }
}
