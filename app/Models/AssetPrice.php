<?php

namespace App\Models;

use App\Enums\PriceSource;
use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Cotação de fechamento de um ativo numa data. Na mesma data, a manual prevalece sobre a automática.
 *
 * @property int $id
 * @property int $household_id
 * @property int $asset_id
 * @property Carbon $date
 * @property string $price
 * @property PriceSource $source
 */
#[Fillable(['asset_id', 'date', 'price', 'source'])]
class AssetPrice extends Model
{
    use BelongsToHousehold;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'source' => PriceSource::class,
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
