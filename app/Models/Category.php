<?php

namespace App\Models;

use App\Enums\CategoryType;
use App\Models\Concerns\BelongsToHousehold;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $household_id
 * @property int|null $parent_id
 * @property string $name
 * @property CategoryType $type
 * @property string|null $color
 */
#[Fillable(['parent_id', 'name', 'type', 'color'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use BelongsToHousehold, HasFactory;

    protected function casts(): array
    {
        return [
            'type' => CategoryType::class,
        ];
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Nome com o pai, ex.: "Moradia › Aluguel".
     */
    public function fullName(): string
    {
        return $this->parent !== null ? "{$this->parent->name} › {$this->name}" : $this->name;
    }
}
