<?php

namespace App\Domain\Accounts;

use App\Models\Account;

class ArchiveAccount
{
    /**
     * Arquiva (ou reativa) a conta. Conta arquivada some das listas de seleção,
     * mas mantém o histórico de lançamentos.
     */
    public function execute(Account $account, bool $archive = true): Account
    {
        $account->archived_at = $archive ? now() : null;
        $account->save();

        return $account;
    }
}
