<?php

namespace App\Models;

use App\Enums\ReportView;
use App\Models\Concerns\BelongsToHousehold;
use Database\Factories\ReportEmailFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Registro de um resumo enviado por e-mail: quem enviou, para quem, quais partes e em que visão.
 *
 * @property int $id
 * @property int $household_id
 * @property int $sender_id
 * @property list<string> $recipients
 * @property list<string> $sections
 * @property ReportView $view
 * @property Carbon|null $month
 * @property Carbon $created_at
 */
#[Fillable(['sender_id', 'recipients', 'sections', 'view', 'month'])]
class ReportEmail extends Model
{
    use BelongsToHousehold;

    /** @use HasFactory<ReportEmailFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'sections' => 'array',
            'view' => ReportView::class,
            'month' => 'date',
        ];
    }
}
