<?php

namespace App\Models;

use App\Enums\HouseholdRole;
use Database\Factories\HouseholdFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name'])]
class Household extends Model
{
    /** @use HasFactory<HouseholdFactory> */
    use HasFactory;

    /**
     * @return BelongsToMany<User, $this, HouseholdMember>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(HouseholdMember::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function addUser(User $user, HouseholdRole $role): void
    {
        $this->users()->attach($user, ['role' => $role->value]);

        if ($user->current_household_id === null) {
            $user->forceFill(['current_household_id' => $this->id])->save();
        }
    }
}
