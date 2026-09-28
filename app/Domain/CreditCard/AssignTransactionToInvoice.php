<?php

namespace App\Domain\CreditCard;

use App\Models\Account;
use App\Models\CreditCard;
use App\Models\Invoice;
use App\Models\Transaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Coloca um lançamento de cartão na fatura certa e ajusta a competência para o mês da fatura.
 * Não salva: quem chama grava o lançamento. Transferências (pagamento de fatura) não entram em fatura.
 */
class AssignTransactionToInvoice
{
    public function execute(Transaction $transaction, int $monthsAhead = 0): void
    {
        $card = $this->cardOf($transaction);

        if ($card === null || $transaction->transfer_id !== null) {
            $transaction->invoice_id = null;

            return;
        }

        $invoice = $this->invoiceForPurchase($card, $transaction->date, $monthsAhead);

        $transaction->invoice_id = $invoice->id;
        $transaction->competence_date = $invoice->reference_month->copy();
    }

    /**
     * Fatura de uma compra na data informada (ou N meses depois, para parcelas).
     * Se a fatura já está paga, a compra vai para a seguinte.
     */
    public function invoiceForPurchase(CreditCard $card, Carbon $date, int $monthsAhead = 0): Invoice
    {
        $referenceMonth = InvoiceSchedule::forPurchase($card, $date)['reference_month']->addMonthsNoOverflow($monthsAhead);
        $invoice = $this->invoiceFor($card, $referenceMonth);

        while ($invoice->isPaid()) {
            $invoice = $this->invoiceFor($card, $invoice->reference_month->copy()->addMonthNoOverflow());
        }

        return $invoice;
    }

    /**
     * Fatura do mês de referência, criada sob demanda.
     */
    public function invoiceFor(CreditCard $card, Carbon $referenceMonth): Invoice
    {
        $dates = InvoiceSchedule::forReferenceMonth($card, $referenceMonth);

        $find = fn (): ?Invoice => Invoice::withoutGlobalScopes()
            ->where('credit_card_id', $card->id)
            ->whereDate('reference_month', $dates['reference_month'])
            ->first();

        $existing = $find();

        if ($existing !== null) {
            return $existing;
        }

        try {
            $invoice = new Invoice($dates + ['credit_card_id' => $card->id]);
            $invoice->household_id = $card->household_id;
            $invoice->save();

            return $invoice;
        } catch (UniqueConstraintViolationException) {
            // Outra requisição criou a mesma fatura ao mesmo tempo.
            return Invoice::withoutGlobalScopes()
                ->where('credit_card_id', $card->id)
                ->whereDate('reference_month', $dates['reference_month'])
                ->firstOrFail();
        }
    }

    private function cardOf(Transaction $transaction): ?CreditCard
    {
        $account = Account::withoutGlobalScopes()->find($transaction->account_id);

        if ($account === null || ! $account->isCreditCard()) {
            return null;
        }

        return CreditCard::withoutGlobalScopes()->where('account_id', $account->id)->first();
    }
}
