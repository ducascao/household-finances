<?php

namespace App\Domain\NetWorth;

use App\Models\Household;
use App\Models\NetWorthSnapshot;
use Illuminate\Support\Carbon;

/**
 * Grava (ou regrava) a fotografia do mês: uma por pessoa do lar e uma do lar.
 * Data de corte: último dia do mês (ou hoje, no mês corrente).
 */
class TakeSnapshots
{
    public function __construct(
        private readonly NetWorthCalculator $calculator,
    ) {}

    public static function cutoff(Carbon $month): Carbon
    {
        $end = $month->copy()->endOfMonth()->startOfDay();

        return $end->gt(today()) ? today() : $end;
    }

    /**
     * @return int fotografias gravadas
     */
    public function forMonth(Carbon $month, ?Household $only = null): int
    {
        $month = $month->copy()->startOfMonth();
        $cutoff = self::cutoff($month);
        $count = 0;

        $households = $only !== null ? collect([$only]) : Household::query()->with('users')->get();

        foreach ($households as $household) {
            $scopes = [NetWorthScope::household($household)];

            foreach ($household->users as $user) {
                if ($user->current_household_id === $household->id) {
                    $scopes[] = NetWorthScope::person($user, $household);
                }
            }

            foreach ($scopes as $scope) {
                $this->store($household, $scope, $month, $this->calculator->at($scope, $cutoff));
                $count++;
            }
        }

        return $count;
    }

    private function store(Household $household, NetWorthScope $scope, Carbon $month, NetWorthBreakdown $breakdown): void
    {
        $snapshot = NetWorthSnapshot::withoutGlobalScopes()
            ->where('household_id', $household->id)
            ->where('user_id', $scope->user?->id)
            ->whereDate('month', $month)
            ->first() ?? new NetWorthSnapshot(['user_id' => $scope->user?->id, 'month' => $month]);

        $snapshot->household_id = $household->id;
        $snapshot->fill([
            'accounts' => $breakdown->accounts(),
            'investments' => $breakdown->investments(),
            'goods' => $breakdown->goods(),
            'debts' => $breakdown->debts(),
            'net_worth' => $breakdown->netWorth(),
        ])->save();
    }
}
