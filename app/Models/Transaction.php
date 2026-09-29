<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\TransactionStatus;
use App\Jobs\DeleteStoredAttachment;
use App\Models\Concerns\BelongsToHousehold;
use Brick\Money\Money;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * @property int $id
 * @property int $household_id
 * @property int $account_id
 * @property string|null $transfer_id
 * @property int|null $recurrence_id
 * @property int|null $invoice_id
 * @property int|null $installment_group_id
 * @property int|null $installment_number
 * @property string|null $import_hash
 * @property int|null $asset_operation_id
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
#[Fillable(['transfer_id', 'recurrence_id', 'occurrence_date', 'invoice_id', 'installment_group_id', 'installment_number', 'import_hash', 'asset_operation_id', 'account_id', 'category_id', 'amount', 'status', 'currency', 'date', 'due_date', 'competence_date', 'description', 'paid_by', 'notes', 'tags'])]
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
        // Excluir o lançamento remove os comprovantes: as linhas caem em cascata e os arquivos vão por job.
        static::deleting(function (Transaction $transaction): void {
            Attachment::withoutGlobalScopes()
                ->where('transaction_id', $transaction->id)
                ->get()
                ->each(fn (Attachment $attachment) => DeleteStoredAttachment::dispatch($attachment->disk, $attachment->path, $attachment->household_id));
        });

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
     * @return HasMany<Attachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<InstallmentGroup, $this>
     */
    public function installmentGroup(): BelongsTo
    {
        return $this->belongsTo(InstallmentGroup::class);
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
     * Receitas e despesas: exclui transferências e compras/vendas de ativos (base dos relatórios).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function incomeAndExpense(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('transfer_id'))
            ->whereNull($query->qualifyColumn('asset_operation_id'));
    }

    public function isAssetTrade(): bool
    {
        return $this->asset_operation_id !== null;
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
