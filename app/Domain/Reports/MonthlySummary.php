<?php

namespace App\Domain\Reports;

use App\Domain\Accounts\AccountBalance;
use App\Enums\CategoryType;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Números do resumo do mês, por competência e sem transferências.
 *
 * Receita e despesa vêm do tipo da categoria (não do sinal): um estorno abate a despesa.
 * Valores em centavos, positivos (entradas e saídas); só lançamentos em BRL entram nos totais.
 * Com usuário logado, os escopos globais limitam às contas visíveis a ele.
 */
class MonthlySummary
{
    public const CURRENCY = 'BRL';

    /**
     * @return array{income: array{paid: int, scheduled: int, total: int}, expense: array{paid: int, scheduled: int, total: int}, result: int}
     */
    public function totals(Carbon $month): array
    {
        $rows = $this->entries()
            ->whereDate('transactions.competence_date', $month->copy()->startOfMonth())
            ->selectRaw('categories.type, transactions.status, sum(transactions.amount) as total')
            ->groupBy('categories.type', 'transactions.status')
            ->get();

        $sum = fn (CategoryType $type, TransactionStatus $status): int => (int) $rows
            ->where('type', $type->value)->where('status', $status->value)->sum('total');

        $income = [
            'paid' => $sum(CategoryType::Income, TransactionStatus::Paid),
            'scheduled' => $sum(CategoryType::Income, TransactionStatus::Scheduled),
        ];
        $expense = [
            'paid' => -$sum(CategoryType::Expense, TransactionStatus::Paid),
            'scheduled' => -$sum(CategoryType::Expense, TransactionStatus::Scheduled),
        ];
        $income['total'] = $income['paid'] + $income['scheduled'];
        $expense['total'] = $expense['paid'] + $expense['scheduled'];

        return [
            'income' => $income,
            'expense' => $expense,
            'result' => $income['total'] - $expense['total'],
        ];
    }

    /**
     * Despesas do mês por categoria principal, com as subcategorias. Ordenadas do maior para o menor.
     *
     * @return list<array{category: Category, total: int, percent: float, children: list<array{category: Category, total: int}>}>
     */
    public function expensesByCategory(Carbon $month): array
    {
        $rows = $this->entries()
            ->where('categories.type', CategoryType::Expense->value)
            ->whereDate('transactions.competence_date', $month->copy()->startOfMonth())
            ->selectRaw('categories.id as category_id, -sum(transactions.amount) as total')
            ->groupBy('categories.id')
            ->pluck('total', 'category_id');

        $categories = Category::withoutGlobalScopes()->whereKey($rows->keys())->with('parent')->get()->keyBy('id');
        $grandTotal = (int) $rows->sum();
        $roots = [];

        foreach ($rows as $categoryId => $total) {
            $category = $categories->get($categoryId);

            if ($category === null) {
                continue;
            }

            $root = $category->parent ?? $category;

            $roots[$root->id] ??= ['category' => $root, 'total' => 0, 'percent' => 0.0, 'children' => []];
            $roots[$root->id]['total'] += (int) $total;

            if ($category->parent !== null) {
                $roots[$root->id]['children'][] = ['category' => $category, 'total' => (int) $total];
            }
        }

        foreach ($roots as &$root) {
            $root['percent'] = $grandTotal > 0 ? round($root['total'] / $grandTotal * 100, 1) : 0.0;
            usort($root['children'], fn (array $a, array $b): int => $b['total'] <=> $a['total']);
        }

        usort($roots, fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return $roots;
    }

    /**
     * Entradas, saídas e resultado dos N meses que terminam no mês informado (meses sem lançamento vêm zerados).
     *
     * @return list<array{month: Carbon, income: int, expense: int, result: int}>
     */
    public function evolution(Carbon $lastMonth, int $months = 12): array
    {
        $last = $lastMonth->copy()->startOfMonth();
        $first = $last->copy()->subMonthsNoOverflow($months - 1);

        $rows = $this->entries()
            ->whereBetween('transactions.competence_date', [$first->toDateString(), $last->toDateString()])
            ->selectRaw("to_char(transactions.competence_date, 'YYYY-MM') as month, categories.type, sum(transactions.amount) as total")
            ->groupBy('month', 'categories.type')
            ->get();

        $series = [];

        for ($month = $first->copy(); $month->lte($last); $month->addMonthNoOverflow()) {
            $key = $month->format('Y-m');
            $income = (int) $rows->where('month', $key)->where('type', CategoryType::Income->value)->sum('total');
            $expense = -(int) $rows->where('month', $key)->where('type', CategoryType::Expense->value)->sum('total');

            $series[] = ['month' => $month->copy(), 'income' => $income, 'expense' => $expense, 'result' => $income - $expense];
        }

        return $series;
    }

    /**
     * Saldo das contas ativas no fim do mês: pagos até o fim do mês e, se o mês ainda não acabou,
     * os previstos que vencem até lá (inclusive atrasados).
     *
     * @return list<array{account: Account, today: int, scheduled: int, projected: int}>
     */
    public function projection(Carbon $month): array
    {
        $end = $month->copy()->endOfMonth()->startOfDay();

        return Account::query()->active()->orderBy('name')->get()
            ->map(function (Account $account) use ($end): array {
                $today = AccountBalance::of($account)->getMinorAmount()->toInt();
                $projected = AccountBalance::at($account, $end)->getMinorAmount()->toInt();

                return [
                    'account' => $account,
                    'today' => $today,
                    'scheduled' => $projected - $today,
                    'projected' => $projected,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Moedas diferentes de BRL com lançamentos no mês (ficam fora dos totais até a conversão de moeda).
     *
     * @return list<string>
     */
    public function otherCurrencies(Carbon $month): array
    {
        return Transaction::query()
            ->incomeAndExpense()
            ->where('currency', '!=', self::CURRENCY)
            ->whereDate('competence_date', $month->copy()->startOfMonth())
            ->distinct()
            ->orderBy('currency')
            ->pluck('currency')
            ->all();
    }

    /**
     * Receitas e despesas (sem transferências) em BRL, com a categoria, respeitando os escopos globais.
     */
    private function entries(): QueryBuilder
    {
        return Transaction::query()
            ->incomeAndExpense()
            ->where('transactions.currency', self::CURRENCY)
            ->join('categories', 'categories.id', '=', 'transactions.category_id')
            ->toBase();
    }

    /**
     * @return Collection<int, Carbon>
     */
    public static function monthOptions(int $back = 36, int $ahead = 12): Collection
    {
        $current = today()->startOfMonth();

        return collect(range(-$ahead, $back))->map(fn (int $offset): Carbon => $current->copy()->subMonthsNoOverflow($offset));
    }
}
