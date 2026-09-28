<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Concerns\BelongsToHousehold;
use Brick\Money\Money;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 * @property int $category_id
 * @property Money $amount
 * @property string $currency
 * @property Carbon $date
 * @property Carbon $competence_date
 * @property string $description
 * @property int $paid_by
 * @property string|null $notes
 * @property list<string> $tags
 */
#[Fillable(['account_id', 'category_id', 'amount', 'currency', 'date', 'competence_date', 'description', 'paid_by', 'notes', 'tags'])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use BelongsToHousehold, HasFactory;

    protected $attributes = [
        'tags' => '[]',
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
            'date' => 'date',
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
     * @return BelongsTo<User, $this>
     */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
