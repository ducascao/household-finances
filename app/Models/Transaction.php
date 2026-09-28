<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\TransactionStatus;
use App\Models\Concerns\BelongsToHousehold;
use Brick\Money\Money;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * @property int $id
 * @property int $household_id
 * @property int $account_id
 * @property string|null $transfer_id
 * @property int|null $recurrence_id
 * @property Carbon|null $occurrence_date
 * @property int|null $category_id
 * @property Money $amount
 * @property TransactionStatus $status
 * @property string $currency
 * @property Carbon $date
 * @property Carbon|null $due_date
 * @property Carbon $competence_date
 * @property string $description
 * @property int $paid_by
 * @property string|null $notes
 * @property list<string> $tags
 */
#[Fillable(['transfer_id', 'recurrence_id', 'occurrence_date', 'account_id', 'category_id', 'amount', 'status', 'currency', 'date', 'due_date', 'competence_date', 'description', 'paid_by', 'notes', 'tags'])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use BelongsToHousehold, HasFactory;

    protected $attributes = [
        'tags' => '[]',
        'status' => 'paid',
    ];

    protected static function booted(): void
    {
        // Lançamento herda a visibilidade da conta: whereHas aplica o escopo de visibilidade de Account.
        static::addGlobalScope('visible_account', function (Builder $query): void {
            if (Auth::user() instanceof User) {
                $query->whereHas('account');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'status' => TransactionStatus::class,
            'date' => 'date',
            'due_date' => 'date',
            'occurrence_date' => 'date',
            'competence_date' => 'date',
            'tags' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Recorrência que gerou o lançamento.
     *
     * @return BelongsTo<Recurrence, $this>
     */
    public function recurrence(): BelongsTo
    {
        return $this->belongsTo(Recurrence::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function isTransfer(): bool
    {
        return $this->transfer_id !== null;
    }

    public function isScheduled(): bool
    {
        return $this->status === TransactionStatus::Scheduled;
    }

    /**
     * Previsto com vencimento anterior a hoje.
     */
    public function isOverdue(): bool
    {
        return $this->isScheduled() && $this->due_date !== null && $this->due_date->lt(today());
    }

    /**
     * Receitas e despesas: exclui transferências (base dos relatórios).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function incomeAndExpense(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('transfer_id'));
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function paid(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), TransactionStatus::Paid->value);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function scheduled(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), TransactionStatus::Scheduled->value);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function overdue(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), TransactionStatus::Scheduled->value)
            ->whereDate($query->qualifyColumn('due_date'), '<', today());
    }
}
