<?php

namespace App\Models;

use App\Enums\AccountVisibility;
use App\Models\Concerns\BelongsToHousehold;
use App\Models\Scopes\VisibleAccountScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Meta com valor-alvo e prazo. O progresso é o saldo das contas e o valor dos investimentos vinculados.
 * Visibilidade como a das contas: pessoal (só o dono) ou compartilhada (todo o lar).
 *
 * @property int $id
 * @property int $household_id
 * @property int $owner_id
 * @property string $name
 * @property int $target
 * @property Carbon $start_date
 * @property Carbon $deadline
 * @property AccountVisibility $visibility
 * @property Carbon|null $archived_at
 * @property string|null $notes
 */
#[Fillable(['owner_id', 'name', 'target', 'start_date', 'deadline', 'visibility', 'archived_at', 'notes'])]
class Goal extends Model
{
    use BelongsToHousehold;

    protected static function booted(): void
    {
        static::addGlobalScope(new VisibleAccountScope);
    }

    protected function casts(): array
    {
        return [
            'target' => 'integer',
            'start_date' => 'date',
            'deadline' => 'date',
            'visibility' => AccountVisibility::class,
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
     * @return BelongsToMany<Account, $this>
     */
    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class, 'goal_accounts');
    }

    /**
     * @return BelongsToMany<Asset, $this>
     */
    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'goal_assets');
    }

    public function isVisibleTo(User $user): bool
    {
        return $this->household_id === $user->current_household_id
            && ($this->visibility === AccountVisibility::Shared || $this->owner_id === $user->id);
    }
}
