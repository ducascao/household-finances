<?php

namespace App\Domain\Accounts;

use App\Models\Account;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Saldo atual da conta = saldo inicial + soma dos lançamentos.
 */
class AccountBalance
{
    /**
     * Adiciona a coluna current_balance (em centavos) à consulta de contas, numa só query.
     *
     * @param  Builder<Account>  $query
     * @return Builder<Account>
     */
    public static function addToQuery(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('accounts.*');
        }

        return $query->selectSub(
            DB::table('transactions')
                ->selectRaw('accounts.initial_balance + coalesce(sum(transactions.amount), 0)')
                ->whereColumn('transactions.account_id', 'accounts.id'),
            'current_balance',
        );
    }

    public static function of(Account $account): Money
    {
        $minor = $account->getAttributes()['current_balance'] ?? null;

        if ($minor === null) {
            $minor = $account->initial_balance->getMinorAmount()->toInt()
                + (int) DB::table('transactions')->where('account_id', $account->id)->sum('amount');
        }

        return Money::ofMinor((int) $minor, $account->currency);
    }

    /**
     * Soma dos saldos por moeda (não converte entre moedas).
     *
     * @param  Collection<int, Account>  $accounts
     * @return array<string, Money>
     */
    public static function totalsByCurrency(Collection $accounts): array
    {
        $totals = [];

        foreach ($accounts as $account) {
            $balance = self::of($account);
            $totals[$account->currency] = isset($totals[$account->currency])
                ? $totals[$account->currency]->plus($balance)
                : $balance;
        }

        ksort($totals);

        return $totals;
    }
}
