<?php

namespace App\Models;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportFormat;
use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Um arquivo importado, com suas linhas em revisão até a confirmação.
 *
 * @property int $id
 * @property int $household_id
 * @property int $account_id
 * @property int $user_id
 * @property string $file_name
 * @property ImportFormat $format
 * @property string|null $reader
 * @property ImportBatchStatus $status
 * @property Carbon|null $confirmed_at
 */
#[Fillable(['account_id', 'user_id', 'file_name', 'format', 'reader', 'status', 'confirmed_at'])]
class ImportBatch extends Model
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
            'format' => ImportFormat::class,
            'status' => ImportBatchStatus::class,
            'confirmed_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ImportLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ImportLine::class);
    }

    public function isReviewing(): bool
    {
        return $this->status === ImportBatchStatus::Reviewing;
    }
}
