<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Ajuste do saldo devedor para bater com o banco ("Ajustar saldo devedor"). amount = informado − calculado.
 *
 * @property int $id
 * @property int $household_id
 * @property int $debt_id
 * @property Carbon $date
 * @property int $amount
 */
#[Fillable(['debt_id', 'date', 'amount'])]
class DebtAdjustment extends Model
{
    use BelongsToHousehold;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount' => 'integer',
        ];
    }
}
