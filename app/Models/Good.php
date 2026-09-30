<?php

namespace App\Models;

use App\Enums\AccountVisibility;
use App\Enums\GoodType;
use App\Models\Concerns\BelongsToHousehold;
use App\Models\Scopes\VisibleAccountScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Bem (imóvel, veículo, outro), valorizado pelo valor informado por data. Visibilidade como a das contas.
 *
 * @property int $id
 * @property int $household_id
 * @property int $owner_id
 * @property string $name
 * @property GoodType $type
 * @property AccountVisibility $visibility
 * @property Carbon $acquisition_date
 * @property int $acquisition_value
 * @property Carbon|null $sale_date
 * @property int|null $sale_value
 * @property int|null $debt_id
 * @property string|null $notes
 */
#[Fillable(['owner_id', 'name', 'type', 'visibility', 'acquisition_date', 'acquisition_value', 'sale_date', 'sale_value', 'debt_id', 'notes'])]
class Good extends Model
{
    use BelongsToHousehold;

    protected static function booted(): void
    {
        static::addGlobalScope(new VisibleAccountScope);
    }

    protected function casts(): array
    {
        return [
            'type' => GoodType::class,
            'visibility' => AccountVisibility::class,
            'acquisition_date' => 'date',
            'acquisition_value' => 'integer',
            'sale_date' => 'date',
            'sale_value' => 'integer',
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
     * @return BelongsTo<Debt, $this>
     */
    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }

    /**
     * @return HasMany<GoodValuation, $this>
     */
    public function valuations(): HasMany
    {
        return $this->hasMany(GoodValuation::class);
    }

    public function isVisibleTo(User $user): bool
    {
        return $this->household_id === $user->current_household_id
            && ($this->visibility === AccountVisibility::Shared || $this->owner_id === $user->id);
    }
}
