<?php

namespace App\Domain\CreditCard;

use App\Domain\Reports\CategoryTotals;
use App\Enums\CategoryType;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\Transaction;

/**
 * Gastos da fatura por categoria, como no resumo do mês.
 *
 * Valores em centavos na moeda do cartão. Despesas positivas (estornos abatem a categoria deles);
 * itens em categoria de receita (cashback, créditos) vão para "credits", negativo.
 * categorias + credits = total da fatura (InvoiceTotals::amountDue).
 */
class InvoiceCategoryBreakdown
{
    /**
     * @return array{categories: list<array{category: Category, total: int, percent: float, children: list<array{category: Category, total: int}>}>, credits: int, total: int}
     */
    public static function of(Invoice $invoice): array
    {
        $rows = Transaction::query()
            ->where('transactions.invoice_id', $invoice->id)
            ->leftJoin('categories', 'categories.id', '=', 'transactions.category_id')
            ->selectRaw('transactions.category_id, categories.type, -sum(transactions.amount) as total')
            ->groupBy('transactions.category_id', 'categories.type')
            ->toBase()
            ->get();

        $expenses = $rows->where('type', CategoryType::Expense->value)->pluck('total', 'category_id');
        $credits = (int) $rows->where('type', '!=', CategoryType::Expense->value)->sum('total');

        return [
            'categories' => CategoryTotals::group($expenses),
            'credits' => $credits,
            'total' => (int) $expenses->sum() + $credits,
        ];
    }
}
