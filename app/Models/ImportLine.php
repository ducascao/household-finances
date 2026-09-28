<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\ImportLineAction;
use App\Enums\ImportLineStatus;
use App\Models\Concerns\BelongsToHousehold;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * @property int $id
 * @property int $household_id
 * @property int $import_batch_id
 * @property int $line_number
 * @property Carbon $date
 * @property string $description
 * @property Money $amount
 * @property string $currency
 * @property string $hash
 * @property ImportLineStatus $status
 * @property ImportLineAction $action
 * @property int|null $category_id
 * @property string|null $final_description
 * @property int|null $import_rule_id
 * @property int|null $matched_transaction_id
 * @property int|null $transaction_id
 */
#[Fillable(['import_batch_id', 'line_number', 'date', 'description', 'amount', 'currency', 'hash', 'status', 'action', 'category_id', 'final_description', 'import_rule_id', 'matched_transaction_id', 'transaction_id'])]
class ImportLine extends Model
{
    use BelongsToHousehold;

    protected static function booted(): void
    {
        static::addGlobalScope('visible_account', function (Builder $query): void {
            if (Auth::user() instanceof User) {
                $query->whereHas('batch');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount' => MoneyCast::class,
            'status' => ImportLineStatus::class,
            'action' => ImportLineAction::class,
        ];
    }

    /**
     * @return BelongsTo<ImportBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function matchedTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'matched_transaction_id');
    }
}
