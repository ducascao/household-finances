<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Parcela da dívida (centavos). Está paga quando o lançamento ligado a ela está pago.
 * total = amortização + juros + encargos (seguros e taxas); correction = correção do saldo (TR) aplicada antes da parcela.
 *
 * @property int $id
 * @property int $household_id
 * @property int $debt_id
 * @property int $number
 * @property Carbon $due_date
 * @property int $correction
 * @property int $amortization
 * @property int $interest
 * @property int $charges
 * @property int $total
 * @property int $balance_after
 * @property int|null $transaction_id
 */
#[Fillable(['debt_id', 'number', 'due_date', 'correction', 'amortization', 'interest', 'charges', 'total', 'balance_after', 'transaction_id'])]
class DebtInstallment extends Model
{
    use BelongsToHousehold;

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'due_date' => 'date',
            'correction' => 'integer',
            'amortization' => 'integer',
            'charges' => 'integer',
            'interest' => 'integer',
            'total' => 'integer',
            'balance_after' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Debt, $this>
     */
    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
