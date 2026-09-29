<?php

namespace App\Domain\Transactions;

use App\Domain\CreditCard\DeleteInstallmentPurchase;
use App\Domain\Investments\ManageIncomes;
use App\Domain\Investments\ManageOperations;
use App\Domain\Transfers\DeleteTransfer;
use App\Models\Account;
use App\Models\AssetIncome;
use App\Models\AssetOperation;
use App\Models\InstallmentGroup;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class DeleteTransaction
{
    public function __construct(
        private readonly DeleteTransfer $deleteTransfer,
        private readonly DeleteInstallmentPurchase $deleteInstallmentPurchase,
    ) {}

    /**
     * Excluir uma perna de transferência exclui o par; excluir uma parcela exclui a compra parcelada.
     * Lançamento em fatura paga não é excluído.
     */
    public function execute(User $actor, Transaction $transaction): void
    {
        if ($transaction->isTransfer()) {
            $this->deleteTransfer->execute($actor, $transaction);

            return;
        }

        if ($transaction->asset_operation_id !== null) {
            app(ManageOperations::class)->delete($actor, AssetOperation::withoutGlobalScopes()->findOrFail($transaction->asset_operation_id));

            return;
        }

        if ($transaction->asset_income_id !== null) {
            app(ManageIncomes::class)->delete($actor, AssetIncome::withoutGlobalScopes()->findOrFail($transaction->asset_income_id));

            return;
        }

        if ($transaction->installment_group_id !== null) {
            $this->deleteInstallmentPurchase->execute($actor, InstallmentGroup::withoutGlobalScopes()->findOrFail($transaction->installment_group_id));

            return;
        }

        $account = Account::withoutGlobalScopes()->find($transaction->account_id);

        if ($account === null || ! $account->isVisibleTo($actor)) {
            throw ValidationException::withMessages(['account_id' => 'Sem acesso à conta deste lançamento.']);
        }

        if ($transaction->invoice_id !== null && Invoice::withoutGlobalScopes()->find($transaction->invoice_id)?->isPaid()) {
            throw ValidationException::withMessages(['invoice_id' => 'O lançamento está numa fatura paga e não pode ser excluído.']);
        }

        $transaction->delete();
    }

    /**
     * @param  iterable<Transaction>  $transactions
     * @return int quantidade excluída (transferência e compra parcelada contam como 1)
     */
    public function executeMany(User $actor, iterable $transactions): int
    {
        $count = 0;
        $seen = [];

        foreach ($transactions as $transaction) {
            $key = match (true) {
                $transaction->transfer_id !== null => 'transfer:'.$transaction->transfer_id,
                $transaction->installment_group_id !== null => 'installments:'.$transaction->installment_group_id,
                default => null,
            };

            if ($key !== null) {
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
            }

            $this->execute($actor, $transaction);
            $count++;
        }

        return $count;
    }
}
