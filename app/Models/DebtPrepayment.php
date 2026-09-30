<?php

namespace App\Models;

use App\Enums\PrepaymentMode;
use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Amortização extraordinária (centavos).
 *
 * @property int $id
 * @property int $household_id
 * @property int $debt_id
 * @property Carbon $date
 * @property int $amount
 * @property PrepaymentMode $mode
 * @property int|null $transaction_id
 */
#[Fillable(['debt_id', 'date', 'amount', 'mode', 'transaction_id'])]
class DebtPrepayment extends Model
{
    use BelongsToHousehold;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount' => 'integer',
            'mode' => PrepaymentMode::class,
        ];
    }

    /**
     * @return BelongsTo<Debt, $this>
     */
    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }
}
