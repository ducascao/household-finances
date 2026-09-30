<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Reais por 1 unidade da moeda numa data (PTAX venda, fechamento). Dado de mercado, sem household_id.
 *
 * @property int $id
 * @property string $currency
 * @property Carbon $date
 * @property string $rate
 */
#[Fillable(['currency', 'date', 'rate'])]
class ExchangeRate extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }
}
