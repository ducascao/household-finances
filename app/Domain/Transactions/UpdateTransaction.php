<?php

namespace App\Domain\Transactions;

use App\Domain\CreditCard\AssignTransactionToInvoice;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class UpdateTransaction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $actor, Transaction $transaction, array $data): Transaction
    {
        if ($transaction->asset_operation_id !== null) {
            throw ValidationException::withMessages([
                'transaction' => 'Compras e vendas de ativos são editadas pela operação, na Carteira.',
            ]);
        }

        if ($transaction->installment_group_id !== null) {
            throw ValidationException::withMessages([
                'transaction' => 'Parcelas são editadas pela compra parcelada.',
            ]);
        }

        if ($transaction->isTransfer()) {
            throw ValidationException::withMessages([
                'transaction' => 'Transferências são editadas pelo formulário de transferência.',
            ]);
        }

        $currentAccount = Account::withoutGlobalScopes()->find($transaction->account_id);
        $attributes = TransactionData::resolve($actor, $data, $currentAccount);

        $transaction->household_id = $attributes['household_id'];
        unset($attributes['household_id']);
        $transaction->fill($attributes);

        $invoice = $transaction->invoice_id !== null ? Invoice::withoutGlobalScopes()->find($transaction->invoice_id) : null;

        if ($invoice?->isPaid()) {
            if ($transaction->isDirty(['account_id', 'amount', 'date'])) {
                throw ValidationException::withMessages([
                    'amount' => 'O lançamento está numa fatura paga: conta, valor e data não podem mudar.',
                ]);
            }

            // Continua na fatura paga, com a competência dela.
            $transaction->competence_date = $invoice->reference_month->copy();
        } elseif ($transaction->installment_group_id === null) {
            app(AssignTransactionToInvoice::class)->execute($transaction);
        }

        $transaction->save();

        return $transaction;
    }
}
