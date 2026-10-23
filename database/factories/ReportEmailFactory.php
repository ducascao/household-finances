<?php

namespace Database\Factories;

use App\Enums\ReportSection;
use App\Enums\ReportView;
use App\Models\ReportEmail;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportEmail>
 */
class ReportEmailFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => fn (array $attributes) => User::findOrFail($attributes['sender_id'])->current_household_id,
            'sender_id' => User::factory(),
            'recipients' => [fake()->safeEmail()],
            'sections' => [ReportSection::NetWorth->value],
            'view' => ReportView::Shared,
            'month' => null,
        ];
    }
}
