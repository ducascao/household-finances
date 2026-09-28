<?php

namespace App\Domain\CreditCard;

use App\Models\CreditCard;
use App\Models\Invoice;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Totais de faturas e limite do cartão. Valores das compras são negativos; o "total da fatura"
 * é apresentado positivo (quanto há a pagar). Estornos abatem.
 */
class InvoiceTotals
{
    /**
     * Adiciona a coluna amount_due (centavos, positivo = a pagar) à consulta de faturas.
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public static function addToQuery(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('invoices.*');
        }

        return $query->selectSub(
            DB::table('transactions')
                ->selectRaw('coalesce(-sum(transactions.amount), 0)')
                ->whereColumn('transactions.invoice_id', 'invoices.id'),
            'amount_due',
        );
    }

    public static function amountDue(Invoice $invoice): Money
    {
        $minor = $invoice->getAttributes()['amount_due']
            ?? -(int) DB::table('transactions')->where('invoice_id', $invoice->id)->sum('amount');

        return Money::ofMinor((int) $minor, self::currency($invoice));
    }

    /**
     * Limite disponível = limite − compras (inclusive parcelas futuras) em faturas ainda não pagas.
     */
    public static function availableLimit(CreditCard $card): Money
    {
        $committed = -(int) DB::table('transactions')
            ->join('invoices', 'invoices.id', '=', 'transactions.invoice_id')
            ->where('invoices.credit_card_id', $card->id)
            ->whereNull('invoices.paid_at')
            ->sum('transactions.amount');

        return $card->limit->minus(Money::ofMinor($committed, $card->currency));
    }

    private static function currency(Invoice $invoice): string
    {
        return (string) DB::table('credit_cards')->where('id', $invoice->credit_card_id)->value('currency');
    }
}
