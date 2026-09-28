<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\CreditCard;
use App\Models\Invoice;
use App\Models\User;

/**
 * Fatura herda a visibilidade da conta do cartão. Faturas são criadas pelo sistema, não à mão.
 */
class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->current_household_id !== null;
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->canSeeCard($user, $invoice);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return false;
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return false;
    }

    public function pay(User $user, Invoice $invoice): bool
    {
        return ! $invoice->isPaid() && $this->canSeeCard($user, $invoice);
    }

    private function canSeeCard(User $user, Invoice $invoice): bool
    {
        $card = CreditCard::withoutGlobalScopes()->find($invoice->credit_card_id);
        $account = $card !== null ? Account::withoutGlobalScopes()->find($card->account_id) : null;

        return $account !== null && $account->isVisibleTo($user);
    }
}
