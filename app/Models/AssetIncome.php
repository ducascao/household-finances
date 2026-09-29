<?php

namespace App\Models;

use App\Enums\AssetIncomeType;
use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Provento recebido (dividendo, JCP, rendimento de FII). Valores em centavos; o lançamento usa o líquido.
 *
 * @property int $id
 * @property int $household_id
 * @property int $asset_id
 * @property AssetIncomeType $type
 * @property Carbon $date
 * @property int $gross_amount
 * @property int $withheld_tax
 * @property string|null $notes
 */
#[Fillable(['asset_id', 'type', 'date', 'gross_amount', 'withheld_tax', 'notes'])]
class AssetIncome extends Model
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
            'type' => AssetIncomeType::class,
            'date' => 'date',
            'gross_amount' => 'integer',
            'withheld_tax' => 'integer',
        ];
    }

    public function netAmount(): int
    {
        return $this->gross_amount - $this->withheld_tax;
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
