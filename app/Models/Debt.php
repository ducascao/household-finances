<?php

namespace App\Models;

use App\Enums\DebtStatus;
use App\Enums\DebtSystem;
use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Financiamento ou empréstimo. Herda a visibilidade da conta de pagamento.
 *
 * @property int $id
 * @property int $household_id
 * @property string $name
 * @property string $creditor
 * @property int $principal
 * @property string $monthly_rate
 * @property DebtSystem $system
 * @property bool $tr_correction
 * @property string $insurance_rate
 * @property int $monthly_fee
 * @property int $installments_count
 * @property Carbon $first_due_date
 * @property int $payment_account_id
 * @property int $category_id
 * @property DebtStatus $status
 * @property string|null $notes
 */
#[Fillable(['name', 'creditor', 'principal', 'monthly_rate', 'system', 'tr_correction', 'insurance_rate', 'monthly_fee', 'installments_count', 'first_due_date', 'payment_account_id', 'category_id', 'status', 'notes'])]
class Debt extends Model
{
    use BelongsToHousehold;

    protected static function booted(): void
    {
        static::addGlobalScope('visible_account', function (Builder $query): void {
            if (Auth::user() instanceof User) {
                $query->whereHas('paymentAccount');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'principal' => 'integer',
            'system' => DebtSystem::class,
            'tr_correction' => 'boolean',
            'monthly_fee' => 'integer',
            'status' => DebtStatus::class,
            'installments_count' => 'integer',
            'first_due_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payment_account_id');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasMany<DebtInstallment, $this>
     */
    public function installments(): HasMany
    {
        return $this->hasMany(DebtInstallment::class);
    }

    /**
     * @return HasMany<DebtAdjustment, $this>
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(DebtAdjustment::class);
    }

    /**
     * @return HasMany<DebtPrepayment, $this>
     */
    public function prepayments(): HasMany
    {
        return $this->hasMany(DebtPrepayment::class);
    }
}
