<?php

namespace App\Domain\Attachments;

use App\Jobs\DeleteStoredAttachment;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class DeleteAttachment
{
    public function execute(User $actor, Attachment $attachment): void
    {
        $transaction = Transaction::withoutGlobalScopes()->find($attachment->transaction_id);
        $account = $transaction !== null ? Account::withoutGlobalScopes()->find($transaction->account_id) : null;

        if ($account === null || ! $account->isVisibleTo($actor)) {
            throw ValidationException::withMessages(['attachment' => 'Sem acesso a este comprovante.']);
        }

        $attachment->delete();
        DeleteStoredAttachment::dispatch($attachment->disk, $attachment->path, $attachment->household_id);
    }
}
