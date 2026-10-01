<?php

namespace App\Domain\Accounts;

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * "Ajustar saldo": grava o saldo real da conta no fim de uma data como saldo inicial. Daí em diante só os
 * lançamentos pagos depois dessa data mexem no saldo; os anteriores ficam no histórico (e na carteira).
 */
class SetOpeningBalance
{
    public function execute(User $actor, Account $account, int $balance, Carbon $date): Account
    {
        if (! $account->isVisibleTo($actor)) {
            throw ValidationException::withMessages(['account' => 'Conta não encontrada.']);
        }

        if ($date->copy()->startOfDay()->gt(today())) {
            throw ValidationException::withMessages(['date' => 'A data do saldo não pode ser futura.']);
        }

        $account->fill(['initial_balance' => $balance, 'balance_date' => $date->copy()->startOfDay()])->save();

        return $account;
    }
}
