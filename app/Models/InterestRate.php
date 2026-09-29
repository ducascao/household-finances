<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Taxa diária de uma série pública (CDI). Dado de mercado, sem household_id.
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

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }
}
