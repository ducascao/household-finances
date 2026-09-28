<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Models\Concerns\BelongsToHousehold;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Fatura de cartão. Mês de referência = mês do vencimento.
 *
 * @property int $id
 * @property int $household_id
 * @property int $credit_card_id
 * @property Carbon $reference_month
 * @property Carbon $closing_date
 * @property Carbon $due_date
 * @property Carbon|null $paid_at
 * @property string|null $payment_transfer_id
 */
#[Fillable(['credit_card_id', 'reference_month', 'closing_date', 'due_date', 'paid_at', 'payment_transfer_id'])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use BelongsToHousehold, HasFactory;

    protected static function booted(): void
    {
        // Herda a visibilidade da conta do cartão.
        static::addGlobalScope('visible_account', function (Builder $query): void {
            if (Auth::user() instanceof User) {
                $query->whereHas('creditCard');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'reference_month' => 'date',
            'closing_date' => 'date',
            'due_date' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CreditCard, $this>
     */
    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Paga (gravado); fechada a partir do dia de fechamento; aberta antes disso.
     */
    public function status(): InvoiceStatus
    {
        return match (true) {
            $this->paid_at !== null => InvoiceStatus::Paid,
            today()->gte($this->closing_date) => InvoiceStatus::Closed,
            default => InvoiceStatus::Open,
        };
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    public function isOverdue(): bool
    {
        return ! $this->isPaid() && $this->due_date->lt(today());
    }

    public function label(): string
    {
        return ucfirst($this->reference_month->locale('pt_BR')->translatedFormat('F/Y'));
    }
}
