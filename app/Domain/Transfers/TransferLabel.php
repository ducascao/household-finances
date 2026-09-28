<?php

namespace App\Domain\Transfers;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;

/**
 * Descrição da perna de transferência para quem está vendo, sem revelar contas pessoais de outros.
 */
class TransferLabel
{
    public static function for(Transaction $leg, User $viewer): string
    {
        $counterpart = Transaction::withoutGlobalScopes()
            ->where('transfer_id', $leg->transfer_id)
            ->whereKeyNot($leg->id)
            ->first();

        $account = $counterpart !== null ? Account::withoutGlobalScopes()->with('owner')->find($counterpart->account_id) : null;

        $outgoing = $leg->amount->isNegative();

        if ($account === null) {
            return 'Transferência';
        }

        $name = $account->isVisibleTo($viewer)
            ? $account->name
            : 'conta pessoal de '.$account->owner->name;

        return $outgoing ? "Transferência para {$name}" : "Transferência de {$name}";
    }
}
