<?php

namespace App\Domain\Transactions;

use App\Models\Transaction;
use App\Models\User;

class CreateTransaction
{
    /**
     * O valor é informado sem sinal: despesa fica negativa e receita positiva, pelo tipo da categoria.
     * Sem competência informada, vale o mês da data.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(User $actor, array $data): Transaction
    {
        $attributes = TransactionData::resolve($actor, $data);

        $transaction = new Transaction;
        $transaction->household_id = $attributes['household_id'];
        unset($attributes['household_id']);
        $transaction->fill($attributes)->save();

        return $transaction;
    }
}
