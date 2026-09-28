<?php

namespace App\Domain\Transfers;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateTransfer
{
    /**
     * Edita a transferência a partir de qualquer uma das pernas: o par é sempre alterado junto.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(User $actor, Transaction $leg, array $data): TransferLegs
    {
        $legs = TransferLegs::of($leg);
        $legs->ensureManageableBy($actor);

        $transfer = TransferData::resolve($actor, $data, [$legs->out->account_id, $legs->in->account_id]);
        // Quem registrou a transferência continua como "pago por".
        $transfer['paid_by'] = $legs->out->paid_by;

        DB::transaction(function () use ($legs, $transfer): void {
            $legs->out->fill(TransferAttributes::for($transfer, $transfer['from']->id, -$transfer['amount']))->save();
            $legs->in->fill(TransferAttributes::for($transfer, $transfer['to']->id, $transfer['amount']))->save();
        });

        return $legs;
    }
}
