<?php

namespace App\Models;

use App\Enums\AssetOperationType;
use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Compra, venda, desdobramento ou grupamento. Quantidade e preço em decimal(20,8) (lidos como string);
 * taxas em centavos. Compra e venda geram um lançamento na conta da corretora.
 *
 * @property int $id
 * @property int $household_id
 * @property int $asset_id
 * @property AssetOperationType $type
 * @property Carbon $date
 * @property string|null $quantity
 * @property string|null $unit_price
 * @property int $fees
 * @property string|null $factor
 * @property string|null $notes
 */
#[Fillable(['asset_id', 'type', 'date', 'quantity', 'unit_price', 'fees', 'factor', 'notes'])]
class AssetOperation extends Model
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
            'type' => AssetOperationType::class,
            'date' => 'date',
            'fees' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return HasOne<Transaction, $this>
     */
    public function transaction(): HasOne
    {
        return $this->hasOne(Transaction::class);
    }
}
