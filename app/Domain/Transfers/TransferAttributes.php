<?php

namespace App\Domain\Transfers;

use App\Models\Account;

class TransferAttributes
{
    /**
     * Atributos de uma perna da transferência.
     *
     * @param  array<string, mixed>  $transfer  saída de TransferData::resolve
     * @return array<string, mixed>
     */
    public static function for(array $transfer, int $accountId, int $amount): array
    {
        /** @var Account $from */
        $from = $transfer['from'];

        return [
            'account_id' => $accountId,
            'category_id' => null,
            'amount' => $amount,
            'currency' => $from->currency,
            'status' => $transfer['status'],
            'date' => $transfer['date'],
            'due_date' => $transfer['due_date'],
            'competence_date' => $transfer['competence_date'],
            'description' => $transfer['description'],
            'paid_by' => $transfer['paid_by'],
            'notes' => $transfer['notes'],
        ];
    }
}
