<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Conta Google conectada ao lar (Drive com escopo drive.file).
 *
 * @property int $id
 * @property int $household_id
 * @property string $email
 * @property string $refresh_token
 * @property string $root_folder
 * @property int|null $connected_by
 */
#[Fillable(['email', 'refresh_token', 'root_folder', 'connected_by'])]
#[Hidden(['refresh_token'])]
class GoogleConnection extends Model
{
    use BelongsToHousehold;

    protected function casts(): array
    {
        return [
            'refresh_token' => 'encrypted',
        ];
    }
}
