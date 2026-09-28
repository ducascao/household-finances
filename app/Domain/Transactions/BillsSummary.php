<?php

namespace App\Domain\Transactions;

use App\Models\Transaction;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Números de contas a pagar/receber: só previstos, sem transferências.
 * Com usuário logado, os escopos globais limitam às contas visíveis a ele.
 */
class BillsSummary
{
    public const DUE_SOON_DAYS = 7;

    /**
     * @return array{count: int, totals: array<string, Money>}
     */
    public function overdue(): array
    {
        return $this->summarize(Transaction::query()->incomeAndExpense()->overdue());
    }

    /**
     * Vencendo de hoje até daqui a 7 dias.
     *
     * @return array{count: int, totals: array<string, Money>}
     */
    public function dueSoon(): array
    {
        return $this->summarize($this->dueSoonQuery());
    }

    /**
     * Previstos com vencimento no mês corrente, separados em a pagar (despesas) e a receber (receitas).
     *
     * @return array{payable: array<string, Money>, receivable: array<string, Money>}
     */
    public function forecastForMonth(?Carbon $month = null): array
    {
        $month ??= today();

        $query = fn () => Transaction::query()
            ->incomeAndExpense()
            ->scheduled()
            ->whereBetween('due_date', [$month->copy()->startOfMonth()->toDateString(), $month->copy()->endOfMonth()->toDateString()]);

        return [
            'payable' => $this->summarize($query()->where('amount', '<', 0))['totals'],
            'receivable' => $this->summarize($query()->where('amount', '>', 0))['totals'],
        ];
    }

    /**
     * Atrasados e vencendo em 7 dias, para a lista do painel.
     *
     * @return Builder<Transaction>
     */
    public function upcomingQuery(): Builder
    {
        return Transaction::query()
            ->incomeAndExpense()
            ->scheduled()
            ->whereDate('due_date', '<=', today()->addDays(self::DUE_SOON_DAYS));
    }

    /**
     * @return Builder<Transaction>
     */
    private function dueSoonQuery(): Builder
    {
        return Transaction::query()
            ->incomeAndExpense()
            ->scheduled()
            ->whereBetween('due_date', [today()->toDateString(), today()->addDays(self::DUE_SOON_DAYS)->toDateString()]);
    }

    /**
     * @param  Builder<Transaction>  $query
     * @return array{count: int, totals: array<string, Money>}
     */
    private function summarize(Builder $query): array
    {
        $rows = $query->toBase()
            ->selectRaw('transactions.currency, count(*) as quantity, sum(transactions.amount) as total')
            ->groupBy('transactions.currency')
            ->orderBy('transactions.currency')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[(string) $row->currency] = Money::ofMinor((int) $row->total, (string) $row->currency);
        }

        return [
            'count' => (int) $rows->sum('quantity'),
            'totals' => $totals,
        ];
    }
}
