<?php

namespace Database\Factories;

use App\Models\CreditCard;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Para as datas corretas use InvoiceSchedule/AssignTransactionToInvoice; a factory grava como vier.
 *
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'credit_card_id' => CreditCard::factory(),
            'household_id' => fn (array $attributes) => CreditCard::withoutGlobalScopes()->findOrFail($attributes['credit_card_id'])->household_id,
            'reference_month' => today()->startOfMonth(),
            'closing_date' => today()->startOfMonth()->day(3),
            'due_date' => today()->startOfMonth()->day(10),
        ];
    }
}
