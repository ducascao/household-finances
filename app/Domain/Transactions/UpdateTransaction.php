<?php

namespace App\Domain\Transactions;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;

class UpdateTransaction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $actor, Transaction $transaction, array $data): Transaction
    {
        $currentAccount = Account::withoutGlobalScopes()->find($transaction->account_id);
        $attributes = TransactionData::resolve($actor, $data, $currentAccount);

        $transaction->household_id = $attributes['household_id'];
        unset($attributes['household_id']);
        $transaction->fill($attributes)->save();

        return $transaction;
    }
}
