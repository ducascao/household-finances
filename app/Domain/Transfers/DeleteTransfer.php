<?php

namespace App\Domain\Transfers;

use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteTransfer
{
    /**
     * Exclui as duas pernas da transferência.
     */
    public function execute(User $actor, Transaction $leg): void
    {
        $legs = TransferLegs::of($leg);
        $legs->ensureManageableBy($actor);

        DB::transaction(function () use ($legs): void {
            foreach ($legs->all() as $transaction) {
                $transaction->delete();
            }

            // Excluir o pagamento de uma fatura reabre a fatura.
            Invoice::withoutGlobalScopes()
                ->where('payment_transfer_id', $legs->out->transfer_id)
                ->update(['paid_at' => null, 'payment_transfer_id' => null]);
        });
    }
}
