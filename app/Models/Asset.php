<?php

namespace App\Models;

use App\Enums\AssetType;
use App\Enums\Indexer;
use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Ativo da carteira (ação, FII, ETF, BDR) numa conta de corretora. Herda a visibilidade da conta.
 *
 * @property int $id
 * @property int $household_id
 * @property int $account_id
 * @property AssetType $type
 * @property string|null $ticker
 * @property string $name
 * @property string|null $issuer
 * @property Indexer|null $indexer
 * @property string|null $rate
 * @property Carbon|null $maturity_date
 * @property string $currency
 */
#[Fillable(['account_id', 'type', 'ticker', 'name', 'issuer', 'indexer', 'rate', 'maturity_date', 'currency'])]
class Asset extends Model
{
    use BelongsToHousehold;

    protected static function booted(): void
    {
        static::addGlobalScope('visible_account', function (Builder $query): void {
            if (Auth::user() instanceof User) {
                $query->whereHas('account');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'type' => AssetType::class,
            'indexer' => Indexer::class,
            'maturity_date' => 'date',
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
     * @return HasMany<AssetOperation, $this>
     */
    public function operations(): HasMany
    {
        return $this->hasMany(AssetOperation::class);
    }

    /**
     * @return HasMany<AssetIncome, $this>
     */
    public function incomes(): HasMany
    {
        return $this->hasMany(AssetIncome::class);
    }

    /**
     * @return HasMany<ManualValuation, $this>
     */
    public function valuations(): HasMany
    {
        return $this->hasMany(ManualValuation::class);
    }

    /**
     * Nome curto para listas e lançamentos: o ticker, ou o nome quando não há ticker.
     */
    public function label(): string
    {
        return $this->ticker ?? $this->name;
    }

    /**
     * @return HasMany<AssetPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(AssetPrice::class);
    }
}
