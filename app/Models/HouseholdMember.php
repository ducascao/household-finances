<?php

namespace App\Models;

use App\Enums\HouseholdRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property HouseholdRole $role
 */
class HouseholdMember extends Pivot
{
    public $incrementing = true;

    protected $table = 'household_user';

    protected function casts(): array
    {
        return [
            'role' => HouseholdRole::class,
        ];
    }
}
