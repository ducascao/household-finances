<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Valor estimado de um bem numa data (centavos).
 *
 * @property int $id
 * @property int $household_id
 * @property int $good_id
 * @property Carbon $date
 * @property int $value
 */
#[Fillable(['good_id', 'date', 'value'])]
class GoodValuation extends Model
{
    use BelongsToHousehold;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'value' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Good, $this>
     */
    public function good(): BelongsTo
    {
        return $this->belongsTo(Good::class);
    }
}
