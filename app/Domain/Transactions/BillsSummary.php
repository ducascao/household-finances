<?php

namespace App\Domain\Transactions;

use App\Domain\CreditCard\InvoiceTotals;
use App\Models\Invoice;
use App\Models\Transaction;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Números de contas a pagar/receber: lançamentos previstos (sem transferências) e faturas de cartão não pagas.
 * Itens do cartão entram só pela fatura, para não contar duas vezes.
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
        return $this->merge(
            $this->summarize($this->transactions()->overdue()),
            $this->summarizeInvoices($this->invoicesDueBetween(null, today()->subDay())),
        );
    }

    /**
     * Vencendo de hoje até daqui a 7 dias.
     *
     * @return array{count: int, totals: array<string, Money>}
     */
    public function dueSoon(): array
    {
        [$from, $until] = [today(), today()->addDays(self::DUE_SOON_DAYS)];

        return $this->merge(
            $this->summarize($this->transactions()->scheduled()->whereBetween('due_date', [$from->toDateString(), $until->toDateString()])),
            $this->summarizeInvoices($this->invoicesDueBetween($from, $until)),
        );
    }

    /**
     * Previstos e faturas com vencimento no mês, separados em a pagar (despesas e faturas) e a receber (receitas).
     *
     * @return array{payable: array<string, Money>, receivable: array<string, Money>}
     */
    public function forecastForMonth(?Carbon $month = null): array
    {
        $month ??= today();
        [$from, $until] = [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()->startOfDay()];

        $query = fn () => $this->transactions()
            ->scheduled()
            ->whereBetween('due_date', [$from->toDateString(), $until->toDateString()]);

        return [
            'payable' => $this->merge(
                $this->summarize($query()->where('amount', '<', 0)),
                $this->summarizeInvoices($this->invoicesDueBetween($from, $until)),
            )['totals'],
            'receivable' => $this->summarize($query()->where('amount', '>', 0))['totals'],
        ];
    }

    /**
     * Previstos atrasados e vencendo em 7 dias, para a lista do painel.
     *
     * @return Builder<Transaction>
     */
    public function upcomingQuery(): Builder
    {
        return $this->transactions()
            ->scheduled()
            ->whereDate('due_date', '<=', today()->addDays(self::DUE_SOON_DAYS));
    }

    /**
     * Faturas não pagas, com valor, atrasadas ou vencendo em 7 dias.
     *
     * @return Collection<int, Invoice>
     */
    public function upcomingInvoices(): Collection
    {
        return $this->invoicesDueBetween(null, today()->addDays(self::DUE_SOON_DAYS));
    }

    /**
     * @return Builder<Transaction>
     */
    private function transactions(): Builder
    {
        return Transaction::query()->incomeAndExpense()->whereNull('invoice_id');
    }

    /**
     * @return Collection<int, Invoice>
     */
    private function invoicesDueBetween(?Carbon $from, Carbon $until): Collection
    {
        return InvoiceTotals::addToQuery(Invoice::query())
            ->with('creditCard.account')
            ->whereNull('paid_at')
            ->when($from, fn (Builder $query) => $query->whereDate('due_date', '>=', $from))
            ->whereDate('due_date', '<=', $until)
            ->orderBy('due_date')
            ->get()
            ->filter(fn (Invoice $invoice): bool => InvoiceTotals::amountDue($invoice)->isPositive())
            ->values();
    }

    /**
     * Faturas entram como valor a pagar (negativo).
     *
     * @param  Collection<int, Invoice>  $invoices
     * @return array{count: int, totals: array<string, Money>}
     */
    private function summarizeInvoices(Collection $invoices): array
    {
        $totals = [];

        foreach ($invoices as $invoice) {
            $due = InvoiceTotals::amountDue($invoice)->negated();
            $currency = $due->getCurrency()->getCurrencyCode();
            $totals[$currency] = isset($totals[$currency]) ? $totals[$currency]->plus($due) : $due;
        }

        return ['count' => $invoices->count(), 'totals' => $totals];
    }

    /**
     * @param  array{count: int, totals: array<string, Money>}  $a
     * @param  array{count: int, totals: array<string, Money>}  $b
     * @return array{count: int, totals: array<string, Money>}
     */
    private function merge(array $a, array $b): array
    {
        $totals = $a['totals'];

        foreach ($b['totals'] as $currency => $money) {
            $totals[$currency] = isset($totals[$currency]) ? $totals[$currency]->plus($money) : $money;
        }

        ksort($totals);

        return ['count' => $a['count'] + $b['count'], 'totals' => $totals];
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
