<?php

namespace App\Domain\CreditCard;

use App\Models\Account;
use App\Models\InstallmentGroup;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteInstallmentPurchase
{
    /**
     * Exclui a compra parcelada inteira. Não é possível se alguma parcela está numa fatura paga.
     */
    public function execute(User $actor, InstallmentGroup $group): void
    {
        $account = Account::withoutGlobalScopes()->find($group->account_id);

        if ($account === null || ! $account->isVisibleTo($actor)) {
            throw ValidationException::withMessages(['installment_group' => 'Sem acesso a esta compra.']);
        }

        $invoiceIds = Transaction::withoutGlobalScopes()->where('installment_group_id', $group->id)->pluck('invoice_id')->filter();

        if (Invoice::withoutGlobalScopes()->whereKey($invoiceIds)->whereNotNull('paid_at')->exists()) {
            throw ValidationException::withMessages([
                'installment_group' => 'Há parcelas em faturas já pagas: a compra parcelada não pode ser excluída.',
            ]);
        }

        DB::transaction(function () use ($group): void {
            Transaction::withoutGlobalScopes()->where('installment_group_id', $group->id)->delete();
            $group->delete();
        });
    }
}
