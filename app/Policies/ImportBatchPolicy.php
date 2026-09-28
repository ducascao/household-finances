<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\ImportBatch;
use App\Models\User;

/**
 * Lote de importação herda a visibilidade da conta. Criado e alterado só pelas ações de importação.
 */
class ImportBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->current_household_id !== null;
    }

    public function view(User $user, ImportBatch $batch): bool
    {
        return $this->canSeeAccount($user, $batch);
    }

    public function create(User $user): bool
    {
        return $user->current_household_id !== null;
    }

    public function update(User $user, ImportBatch $batch): bool
    {
        return $batch->isReviewing() && $this->canSeeAccount($user, $batch);
    }

    public function delete(User $user, ImportBatch $batch): bool
    {
        return false;
    }

    private function canSeeAccount(User $user, ImportBatch $batch): bool
    {
        $account = Account::withoutGlobalScopes()->find($batch->account_id);

        return $account !== null && $account->isVisibleTo($user);
    }
}
