<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Concerns\BelongsToHousehold;
use Brick\Money\Money;
use Database\Factories\CreditCardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

/**
 * Configuração de cartão de uma conta do tipo credit_card (1:1).
 *
 * @property int $id
 * @property int $household_id
 * @property int $account_id
 * @property int $closing_day
 * @property int $due_day
 * @property Money $limit
 * @property string $currency
 */
#[Fillable(['account_id', 'closing_day', 'due_day', 'limit', 'currency'])]
class CreditCard extends Model
{
    /** @use HasFactory<CreditCardFactory> */
    use BelongsToHousehold, HasFactory;

    protected static function booted(): void
    {
        // Herda a visibilidade da conta.
        static::addGlobalScope('visible_account', function (Builder $query): void {
            if (Auth::user() instanceof User) {
                $query->whereHas('account');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'closing_day' => 'integer',
            'due_day' => 'integer',
            'limit' => MoneyCast::class,
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
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
