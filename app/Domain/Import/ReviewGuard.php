<?php

namespace App\Domain\Import;

use App\Models\Account;
use App\Models\ImportBatch;
use App\Models\ImportLine;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Só quem vê a conta mexe no lote, e só enquanto ele está em revisão.
 */
class ReviewGuard
{
    public static function batchFor(User $actor, ImportLine|ImportBatch $subject): ImportBatch
    {
        $batch = $subject instanceof ImportBatch
            ? $subject
            : ImportBatch::withoutGlobalScopes()->findOrFail($subject->import_batch_id);

        $account = Account::withoutGlobalScopes()->find($batch->account_id);

        if ($account === null || ! $account->isVisibleTo($actor)) {
            throw ValidationException::withMessages(['batch' => 'Sem acesso a esta importação.']);
        }

        if (! $batch->isReviewing()) {
            throw ValidationException::withMessages(['batch' => 'Esta importação já foi concluída ou descartada.']);
        }

        return $batch;
    }
}
