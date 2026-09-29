<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Comprovante de um lançamento. Herda a visibilidade do lançamento (e portanto da conta).
 *
 * @property int $id
 * @property int $household_id
 * @property int $transaction_id
 * @property string $disk
 * @property string $path
 * @property string|null $drive_file_id
 * @property string $name
 * @property string $mime_type
 * @property int $size
 * @property int|null $uploaded_by
 */
#[Fillable(['transaction_id', 'disk', 'path', 'drive_file_id', 'name', 'mime_type', 'size', 'uploaded_by'])]
class Attachment extends Model
{
    use BelongsToHousehold;

    protected static function booted(): void
    {
        static::addGlobalScope('visible_account', function (Builder $query): void {
            if (Auth::user() instanceof User) {
                $query->whereHas('transaction');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function isPreviewable(): bool
    {
        return $this->mime_type === 'application/pdf' || in_array($this->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true);
    }

    public function humanSize(): string
    {
        return $this->size >= 1048576
            ? number_format($this->size / 1048576, 1, ',', '.').' MB'
            : number_format(max(1, (int) round($this->size / 1024)), 0, ',', '.').' KB';
    }
}
