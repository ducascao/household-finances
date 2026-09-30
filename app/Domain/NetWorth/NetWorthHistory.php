<?php

namespace App\Domain\NetWorth;

use App\Models\NetWorthSnapshot;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Série mensal do patrimônio para a tela: fotografias gravadas + o mês corrente calculado na hora.
 */
class NetWorthHistory
{
    public function __construct(
        private readonly NetWorthCalculator $calculator,
    ) {}

    public function scope(User $user, bool $household): NetWorthScope
    {
        return $household ? NetWorthScope::household(NetWorthScope::person($user)->household) : NetWorthScope::person($user);
    }

    public function current(User $user, bool $household): NetWorthBreakdown
    {
        return $this->calculator->at($this->scope($user, $household), today());
    }

    /**
     * @return list<array{month: Carbon, accounts: int, investments: int, goods: int, debts: int, net_worth: int, live: bool}>
     */
    public function series(User $user, bool $household, int $months = 24): array
    {
        $from = today()->startOfMonth()->subMonthsNoOverflow($months - 1);

        $rows = NetWorthSnapshot::withoutGlobalScopes()
            ->where('household_id', $user->current_household_id)
            ->where('user_id', $household ? null : $user->id)
            ->whereDate('month', '>=', $from)
            ->whereDate('month', '<', today()->startOfMonth())
            ->orderBy('month')
            ->get()
            ->map(fn (NetWorthSnapshot $snapshot): array => [
                'month' => $snapshot->month,
                'accounts' => $snapshot->accounts,
                'investments' => $snapshot->investments,
                'goods' => $snapshot->goods,
                'debts' => $snapshot->debts,
                'net_worth' => $snapshot->net_worth,
                'live' => false,
            ])
            ->values()
            ->all();

        $now = $this->current($user, $household);
        $rows[] = [
            'month' => today()->startOfMonth(),
            'accounts' => $now->accounts(),
            'investments' => $now->investments(),
            'goods' => $now->goods(),
            'debts' => $now->debts(),
            'net_worth' => $now->netWorth(),
            'live' => true,
        ];

        return $rows;
    }
}
