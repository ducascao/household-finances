<?php

namespace App\Domain\Transactions;

use App\Domain\CreditCard\AssignTransactionToInvoice;
use App\Domain\CreditCard\DeleteEmptyInvoices;
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
        if ($transaction->debt_id !== null) {
            throw ValidationException::withMessages([
                'transaction' => 'Parcelas e amortizações de dívidas são alteradas pela tela da dívida.',
            ]);
        }

        if ($transaction->asset_operation_id !== null || $transaction->asset_income_id !== null) {
            throw ValidationException::withMessages([
                'transaction' => 'Compras, vendas e proventos de ativos são editados pela tela do ativo, na Carteira.',
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
        // Competência em branco segue a data; só a informada pelo usuário escolhe a fatura.
        $competenceChanged = filled($data['competence_date'] ?? null) && $transaction->isDirty('competence_date');

        $invoice = $transaction->invoice_id !== null ? Invoice::withoutGlobalScopes()->find($transaction->invoice_id) : null;

        if ($invoice?->isPaid()) {
            if ($transaction->isDirty(['account_id', 'amount', 'date']) || $competenceChanged) {
                throw ValidationException::withMessages([
                    'amount' => 'O lançamento está numa fatura paga: conta, valor, data e competência não podem mudar.',
                ]);
            }

            // Continua na fatura paga, com a competência dela.
            $transaction->competence_date = $invoice->reference_month->copy();
        } elseif ($competenceChanged) {
            // No cartão, mudar a competência move o lançamento para a fatura daquele mês.
            app(AssignTransactionToInvoice::class)->executeForCompetence($transaction);
        } else {
            app(AssignTransactionToInvoice::class)->execute($transaction);
        }

        $previousInvoiceId = $transaction->getOriginal('invoice_id');
        $transaction->save();

        if ($previousInvoiceId !== $transaction->invoice_id) {
            app(DeleteEmptyInvoices::class)->execute([$previousInvoiceId]);
        }

        return $transaction;
    }
}
