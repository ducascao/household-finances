<?php

namespace App\Domain\CreditCard;

use App\Models\Invoice;

/**
 * Apaga faturas que ficaram sem lançamentos (ex.: a compra mudou de fatura ou foi excluída).
 * Fatura paga ou ligada a um pagamento fica.
 */
class DeleteEmptyInvoices
{
    /**
     * @param  iterable<int|null>  $invoiceIds
     */
    public function execute(iterable $invoiceIds): int
    {
        $ids = collect($invoiceIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return 0;
        }

        return Invoice::withoutGlobalScopes()
            ->whereKey($ids)
            ->whereNull('paid_at')
            ->whereNull('payment_transfer_id')
            ->whereDoesntHave('transactions', fn ($query) => $query->withoutGlobalScopes())
            ->delete();
    }
}
