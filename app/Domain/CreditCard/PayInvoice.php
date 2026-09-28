<?php

namespace App\Domain\CreditCard;

use App\Domain\Transfers\CreateTransfer;
use App\Models\CreditCard;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayInvoice
{
    public function __construct(
        private readonly CreateTransfer $createTransfer,
    ) {}

    /**
     * Paga a fatura com uma transferência da conta escolhida para o cartão e marca a fatura como paga.
     * Valor padrão: o total da fatura. Compras que cairiam nela passam a ir para a fatura seguinte.
     */
    public function execute(User $actor, Invoice $invoice, int $fromAccountId, ?Carbon $date = null, ?int $amount = null): Invoice
    {
        if ($invoice->isPaid()) {
            throw ValidationException::withMessages(['invoice' => 'A fatura já está paga.']);
        }

        $card = CreditCard::withoutGlobalScopes()->findOrFail($invoice->credit_card_id);
        $amount ??= InvoiceTotals::amountDue($invoice)->getMinorAmount()->toInt();

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'A fatura não tem valor a pagar.']);
        }

        return DB::transaction(function () use ($actor, $invoice, $card, $fromAccountId, $date, $amount): Invoice {
            $legs = $this->createTransfer->execute($actor, [
                'from_account_id' => $fromAccountId,
                'to_account_id' => $card->account_id,
                'amount' => $amount,
                'date' => ($date ?? today())->toDateString(),
                'description' => 'Pagamento da fatura '.$invoice->label(),
            ]);

            $invoice->forceFill([
                'paid_at' => now(),
                'payment_transfer_id' => $legs->out->transfer_id,
            ])->save();

            return $invoice;
        });
    }
}
