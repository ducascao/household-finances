<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\AccountType;
use App\Enums\AccountVisibility;
use App\Models\Concerns\BelongsToHousehold;
use App\Models\Scopes\VisibleAccountScope;
use Brick\Money\Money;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $household_id
 * @property int $owner_id
 * @property string $name
 * @property AccountType $type
 * @property AccountVisibility $visibility
 * @property string $currency
 * @property Money $initial_balance
 * @property Carbon|null $balance_date
 * @property Carbon|null $archived_at
 */
#[Fillable(['owner_id', 'name', 'type', 'visibility', 'currency', 'initial_balance', 'balance_date', 'archived_at'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use BelongsToHousehold, HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new VisibleAccountScope);
    }

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'visibility' => AccountVisibility::class,
            'initial_balance' => MoneyCast::class,
            'balance_date' => 'date',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return HasOne<CreditCard, $this>
     */
    public function creditCard(): HasOne
    {
        return $this->hasOne(CreditCard::class);
    }

    /**
     * @return HasOne<ImportProfile, $this>
     */
    public function importProfile(): HasOne
    {
        return $this->hasOne(ImportProfile::class);
    }

    public function isCreditCard(): bool
    {
        return $this->type === AccountType::CreditCard;
    }

    public function isVisibleTo(User $user): bool
    {
        return $this->household_id === $user->current_household_id
            && ($this->visibility === AccountVisibility::Shared || $this->owner_id === $user->id);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('archived_at');
    }
}
