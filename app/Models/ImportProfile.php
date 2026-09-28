<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Mapeamento de colunas do CSV de uma conta. Colunas numeradas a partir de 1.
 *
 * @property int $id
 * @property int $household_id
 * @property int $account_id
 * @property string $name
 * @property string $delimiter
 * @property int $skip_lines
 * @property bool $has_header
 * @property int $date_column
 * @property int $description_column
 * @property int|null $amount_column
 * @property int|null $debit_column
 * @property int|null $credit_column
 * @property string $date_format
 * @property string $decimal_separator
 * @property string|null $thousands_separator
 * @property bool $invert_sign
 */
#[Fillable(['account_id', 'name', 'delimiter', 'skip_lines', 'has_header', 'date_column', 'description_column', 'amount_column', 'debit_column', 'credit_column', 'date_format', 'decimal_separator', 'thousands_separator', 'invert_sign'])]
class ImportProfile extends Model
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
            'skip_lines' => 'integer',
            'has_header' => 'boolean',
            'date_column' => 'integer',
            'description_column' => 'integer',
            'amount_column' => 'integer',
            'debit_column' => 'integer',
            'credit_column' => 'integer',
            'invert_sign' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
