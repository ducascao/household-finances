<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Taxa de uma série pública: CDI diário ou TR mensal (pela data de início do período). Dado de mercado, sem household_id.
 *
 * @property int $id
 * @property string $series
 * @property Carbon $date
 * @property string $rate
 */
#[Fillable(['series', 'date', 'rate'])]
class InterestRate extends Model
{
    public const CDI = 'cdi';

    public const TR = 'tr';

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }
}
