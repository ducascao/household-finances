<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Descrição contém X" → categoria (e descrição amigável) ou ignorar. Vale para o lar todo.
 *
 * @property int $id
 * @property int $household_id
 * @property string $pattern
 * @property int|null $category_id
 * @property string|null $description
 * @property bool $ignore
 * @property int $position
 */
#[Fillable(['pattern', 'category_id', 'description', 'ignore', 'position'])]
class ImportRule extends Model
{
    use BelongsToHousehold;

    protected $attributes = [
        'ignore' => false,
        'position' => 0,
    ];

    protected function casts(): array
    {
        return [
            'ignore' => 'boolean',
            'position' => 'integer',
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
