<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Saldo informado de um ativo de renda fixa ou previdência numa data (centavos).
 *
 * @property int $id
 * @property int $household_id
 * @property int $asset_id
 * @property Carbon $date
 * @property int $balance
 */
#[Fillable(['asset_id', 'date', 'balance'])]
class ManualValuation extends Model
{
    use BelongsToHousehold;

    protected static function booted(): void
    {
        static::addGlobalScope('visible_account', function (Builder $query): void {
            if (Auth::user() instanceof User) {
                $query->whereHas('asset');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'balance' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
