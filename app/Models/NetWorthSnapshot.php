<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Patrimônio no fim de um mês (centavos, reais). user_id preenchido = visão da pessoa (o que ela vê);
 * vazio = visão do lar (só o que está em contas compartilhadas).
 *
 * @property int $id
 * @property int $household_id
 * @property int|null $user_id
 * @property Carbon $month
 * @property int $accounts
 * @property int $investments
 * @property int $goods
 * @property int $debts
 * @property int $net_worth
 */
#[Fillable(['user_id', 'month', 'accounts', 'investments', 'goods', 'debts', 'net_worth'])]
class NetWorthSnapshot extends Model
{
    use BelongsToHousehold;

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'accounts' => 'integer',
            'investments' => 'integer',
            'goods' => 'integer',
            'debts' => 'integer',
            'net_worth' => 'integer',
        ];
    }
}
