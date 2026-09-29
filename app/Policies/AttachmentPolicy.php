<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Transaction;
use App\Models\User;

/**
 * Comprovante herda a visibilidade do lançamento: conta privada, só o dono.
 */
class AttachmentPolicy
{
    public function view(User $user, Attachment $attachment): bool
    {
        return $this->canSeeTransaction($user, $attachment);
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        return $this->canSeeTransaction($user, $attachment);
    }

    private function canSeeTransaction(User $user, Attachment $attachment): bool
    {
        $transaction = Transaction::withoutGlobalScopes()->find($attachment->transaction_id);
        $account = $transaction !== null ? Account::withoutGlobalScopes()->find($transaction->account_id) : null;

        return $account !== null && $account->isVisibleTo($user);
    }
}
