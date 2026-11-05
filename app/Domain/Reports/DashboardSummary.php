<?php

namespace App\Domain\Reports;

use App\Domain\Accounts\AccountBalance;
use App\Domain\CreditCard\InvoiceTotals;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Invoice;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Números do topo do Painel (estilo Organizze): saldo geral, receitas e despesas do mês e faturas atuais
 * dos cartões. Tudo pelo que o usuário logado vê (escopos de lar e de visibilidade das contas).
 */
class DashboardSummary
{
    public function __construct(private readonly MonthlySummary $monthly) {}

    /**
     * Saldo das contas ativas, sem os cartões (cartão não é dinheiro disponível; entra em cards()).
     * Valores em BRL; as outras moedas vêm à parte, sem conversão.
     *
     * @return array{current: Money, projected: Money, others: array<string, Money>}
     */
    public function balance(): array
    {
        $accounts = AccountBalance::addToQuery(Account::query()->active()->where('type', '!=', AccountType::CreditCard->value))->get();
        $current = AccountBalance::totalsByCurrency($accounts);
        $projected = AccountBalance::totalsByCurrency($accounts, projected: true);

        return [
            'current' => $current[MonthlySummary::CURRENCY] ?? Money::zero(MonthlySummary::CURRENCY),
            'projected' => $projected[MonthlySummary::CURRENCY] ?? Money::zero(MonthlySummary::CURRENCY),
            'others' => array_diff_key($current, [MonthlySummary::CURRENCY => true]),
        ];
    }

    /**
     * Receitas e despesas do mês (por competência, sem transferências), em centavos positivos.
     *
     * @return array{income: array{paid: int, scheduled: int, total: int}, expense: array{paid: int, scheduled: int, total: int}, result: int}
     */
    public function month(?Carbon $month = null): array
    {
        return $this->monthly->totals(($month ?? today())->copy()->startOfMonth());
    }

    /**
     * Fatura atual de cada cartão ativo = a mais antiga ainda não paga (inclusive atrasada).
     * Total por moeda (positivo = a pagar), quantos cartões têm fatura em aberto e o vencimento mais próximo.
     *
     * @return array{totals: array<string, Money>, count: int, next_due: Carbon|null}
     */
    public function cards(): array
    {
        $current = InvoiceTotals::addToQuery(Invoice::query())
            ->whereNull('paid_at')
            ->whereHas('creditCard.account', fn (Builder $query) => $query->active())
            ->orderBy('due_date')
            ->get()
            ->unique('credit_card_id');

        $totals = [];

        foreach ($current as $invoice) {
            $due = InvoiceTotals::amountDue($invoice);
            $currency = $due->getCurrency()->getCurrencyCode();
            $totals[$currency] = isset($totals[$currency]) ? $totals[$currency]->plus($due) : $due;
        }

        ksort($totals);

        return [
            'totals' => $totals,
            'count' => $current->count(),
            'next_due' => $current->first()?->due_date,
        ];
    }
}
