<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Concerns\BelongsToHousehold;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Compra parcelada no cartão: cada parcela é um lançamento numa fatura.
 *
 * @property int $id
 * @property int $household_id
 * @property int $account_id
 * @property int $category_id
 * @property Money $total_amount
 * @property string $currency
 * @property int $installments
 * @property string $description
 * @property Carbon $purchase_date
 */
#[Fillable(['account_id', 'category_id', 'total_amount', 'currency', 'installments', 'description', 'purchase_date'])]
class InstallmentGroup extends Model
{
    use BelongsToHousehold;

    protected function casts(): array
    {
        return [
            'total_amount' => MoneyCast::class,
            'installments' => 'integer',
            'purchase_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
