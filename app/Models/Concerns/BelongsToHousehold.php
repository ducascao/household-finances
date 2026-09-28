<?php

namespace App\Models\Concerns;

use App\Models\Household;
use App\Models\Scopes\HouseholdScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Toda tabela de domínio pertence a um lar: filtra as consultas pelo lar do
 * usuário logado e preenche o household_id ao criar.
 */
trait BelongsToHousehold
{
    public static function bootBelongsToHousehold(): void
    {
        static::addGlobalScope(new HouseholdScope);

        static::creating(function (self $model): void {
            if ($model->getAttribute('household_id') === null) {
                $model->setAttribute('household_id', HouseholdScope::currentHouseholdId());
            }
        });
    }

    /**
     * @return BelongsTo<Household, $this>
     */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }
}
