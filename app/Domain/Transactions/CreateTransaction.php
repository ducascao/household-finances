<?php

namespace App\Domain\Transactions;

use App\Domain\CreditCard\AssignTransactionToInvoice;
use App\Models\Transaction;
use App\Models\User;

class CreateTransaction
{
    /**
     * O valor é informado sem sinal: despesa fica negativa e receita positiva, pelo tipo da categoria.
     * Sem competência informada, vale o mês da data (no cartão, a fatura em que a data cai).
     * Com competência informada, no cartão o lançamento vai para a fatura daquele mês.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(User $actor, array $data): Transaction
    {
        $attributes = TransactionData::resolve($actor, $data);

        $transaction = new Transaction;
        $transaction->household_id = $attributes['household_id'];
        unset($attributes['household_id']);
        $transaction->fill($attributes);
        if (filled($data['competence_date'] ?? null)) {
            app(AssignTransactionToInvoice::class)->executeForCompetence($transaction);
        } else {
            app(AssignTransactionToInvoice::class)->execute($transaction);
        }
        $transaction->save();

        return $transaction;
    }
}
