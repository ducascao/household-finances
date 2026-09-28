<?php

namespace App\Domain\Accounts;

use App\Enums\TransactionStatus;
use App\Models\Account;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Saldo atual = saldo inicial + lançamentos pagos.
 * Saldo projetado = saldo atual + previstos com vencimento até o fim do mês corrente (inclui atrasados).
 */
class AccountBalance
{
    /**
     * Adiciona as colunas current_balance e projected_balance (em centavos) à consulta de contas.
     *
     * @param  Builder<Account>  $query
     * @return Builder<Account>
     */
    public static function addToQuery(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('accounts.*');
        }

        return $query
            ->selectSub(
                self::sumQuery()->selectRaw('accounts.initial_balance + coalesce(sum(transactions.amount), 0)')
                    ->where('transactions.status', TransactionStatus::Paid->value),
                'current_balance',
            )
            ->selectSub(
                self::sumQuery()->selectRaw('accounts.initial_balance + coalesce(sum(transactions.amount), 0)')
                    ->where(fn (QueryBuilder $query) => $query
                        ->where('transactions.status', TransactionStatus::Paid->value)
                        ->orWhereDate('transactions.due_date', '<=', self::projectionEnd())),
                'projected_balance',
            );
    }

    public static function of(Account $account): Money
    {
        $minor = $account->getAttributes()['current_balance'] ?? null;

        if ($minor === null) {
            $minor = $account->initial_balance->getMinorAmount()->toInt()
                + (int) DB::table('transactions')
                    ->where('account_id', $account->id)
                    ->where('status', TransactionStatus::Paid->value)
                    ->sum('amount');
        }

        return Money::ofMinor((int) $minor, $account->currency);
    }

    public static function projectedOf(Account $account): Money
    {
        $minor = $account->getAttributes()['projected_balance'] ?? null;

        if ($minor === null) {
            $minor = self::of($account)->getMinorAmount()->toInt()
                + (int) DB::table('transactions')
                    ->where('account_id', $account->id)
                    ->where('status', TransactionStatus::Scheduled->value)
                    ->whereDate('due_date', '<=', self::projectionEnd())
                    ->sum('amount');
        }

        return Money::ofMinor((int) $minor, $account->currency);
    }

    /**
     * Soma dos saldos atuais por moeda (não converte entre moedas).
     *
     * @param  Collection<int, Account>  $accounts
     * @return array<string, Money>
     */
    public static function totalsByCurrency(Collection $accounts, bool $projected = false): array
    {
        $totals = [];

        foreach ($accounts as $account) {
            $balance = $projected ? self::projectedOf($account) : self::of($account);
            $totals[$account->currency] = isset($totals[$account->currency])
                ? $totals[$account->currency]->plus($balance)
                : $balance;
        }

        ksort($totals);

        return $totals;
    }

    public static function projectionEnd(): Carbon
    {
        return today()->endOfMonth()->startOfDay();
    }

    private static function sumQuery(): QueryBuilder
    {
        return DB::table('transactions')->whereColumn('transactions.account_id', 'accounts.id');
    }
}
