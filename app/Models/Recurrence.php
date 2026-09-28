<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\RecurrenceFrequency;
use App\Models\Concerns\BelongsToHousehold;
use Brick\Money\Money;
use Database\Factories\RecurrenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Modelo de lançamento que se repete (conta fixa).
 *
 * @property int $id
 * @property int $household_id
 * @property int $account_id
 * @property int $category_id
 * @property Money $amount
 * @property string $currency
 * @property bool $amount_is_estimate
 * @property string $description
 * @property int $paid_by
 * @property string|null $notes
 * @property list<string> $tags
 * @property RecurrenceFrequency $frequency
 * @property int|null $interval_months
 * @property int|null $day_of_month
 * @property Carbon $start_date
 * @property Carbon $next_date
 * @property Carbon|null $end_date
 */
#[Fillable(['account_id', 'category_id', 'amount', 'currency', 'amount_is_estimate', 'description', 'paid_by', 'notes', 'tags', 'frequency', 'interval_months', 'day_of_month', 'start_date', 'next_date', 'end_date'])]
class Recurrence extends Model
{
    /** @use HasFactory<RecurrenceFactory> */
    use BelongsToHousehold, HasFactory;

    protected $attributes = [
        'tags' => '[]',
        'amount_is_estimate' => false,
    ];

    protected static function booted(): void
    {
        // Herda a visibilidade da conta, como os lançamentos.
        static::addGlobalScope('visible_account', function (Builder $query): void {
            if (Auth::user() instanceof User) {
                $query->whereHas('account');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'amount_is_estimate' => 'boolean',
            'tags' => 'array',
            'frequency' => RecurrenceFrequency::class,
            'interval_months' => 'integer',
            'day_of_month' => 'integer',
            'start_date' => 'date',
            'next_date' => 'date',
            'end_date' => 'date',
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
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
