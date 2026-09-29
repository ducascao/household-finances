<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Concerns\BelongsToHousehold;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Valor orçado para uma categoria de despesa num mês. É do lar (o realizado respeita a visibilidade de quem vê).
 *
 * @property int $id
 * @property int $household_id
 * @property int $category_id
 * @property Carbon $month
 * @property Money $amount
 */
#[Fillable(['category_id', 'month', 'amount'])]
class Budget extends Model
{
    use BelongsToHousehold;

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'amount' => MoneyCast::class,
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
