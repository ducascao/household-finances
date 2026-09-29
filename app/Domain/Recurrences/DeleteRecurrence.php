<?php

namespace App\Domain\Recurrences;

use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Recurrence;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteRecurrence
{
    /**
     * Exclui a recorrência. Os lançamentos gerados ficam (sem vínculo), exceto os previstos
     * de hoje em diante quando $deleteFutureScheduled.
     */
    public function execute(User $actor, Recurrence $recurrence, bool $deleteFutureScheduled): void
    {
        $account = Account::withoutGlobalScopes()->find($recurrence->account_id);

        if ($account === null || ! $account->isVisibleTo($actor)) {
            throw ValidationException::withMessages(['recurrence' => 'Sem acesso a esta recorrência.']);
        }

        DB::transaction(function () use ($recurrence, $deleteFutureScheduled): void {
            if ($deleteFutureScheduled) {
                Transaction::withoutGlobalScopes()
                    ->where('recurrence_id', $recurrence->id)
                    ->where('status', TransactionStatus::Scheduled->value)
                    ->whereDate('occurrence_date', '>=', today())
                    ->get()
                    ->each->delete();
            }

            $recurrence->delete();
        });
    }
}
