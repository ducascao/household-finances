<?php

namespace App\Domain\Transactions;

use App\Domain\Transfers\DeleteTransfer;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class DeleteTransaction
{
    public function __construct(
        private readonly DeleteTransfer $deleteTransfer,
    ) {}

    /**
     * Excluir uma perna de transferência exclui o par.
     */
    public function execute(User $actor, Transaction $transaction): void
    {
        if ($transaction->isTransfer()) {
            $this->deleteTransfer->execute($actor, $transaction);

            return;
        }

        $account = Account::withoutGlobalScopes()->find($transaction->account_id);

        if ($account === null || ! $account->isVisibleTo($actor)) {
            throw ValidationException::withMessages(['account_id' => 'Sem acesso à conta deste lançamento.']);
        }

        $transaction->delete();
    }

    /**
     * @param  iterable<Transaction>  $transactions
     * @return int quantidade excluída (transferência conta como 1)
     */
    public function executeMany(User $actor, iterable $transactions): int
    {
        $count = 0;
        $seenTransfers = [];

        foreach ($transactions as $transaction) {
            if ($transaction->transfer_id !== null) {
                if (isset($seenTransfers[$transaction->transfer_id])) {
                    continue;
                }

                $seenTransfers[$transaction->transfer_id] = true;
            }

            $this->execute($actor, $transaction);
            $count++;
        }

        return $count;
    }
}
