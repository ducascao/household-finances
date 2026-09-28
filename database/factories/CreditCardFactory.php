<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\CreditCard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditCard>
 */
class CreditCardFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory()->state(['type' => AccountType::CreditCard]),
            'household_id' => fn (array $attributes) => Account::withoutGlobalScopes()->findOrFail($attributes['account_id'])->household_id,
            'currency' => fn (array $attributes) => Account::withoutGlobalScopes()->findOrFail($attributes['account_id'])->currency,
            'closing_day' => 3,
            'due_day' => 10,
            'limit' => 500000,
        ];
    }

    public function forAccount(Account $account): static
    {
        return $this->state(fn (array $attributes) => ['account_id' => $account->id]);
    }
}
