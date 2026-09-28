<?php

namespace App\Domain\Transfers;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateTransfer
{
    /**
     * Gera o par de lançamentos: negativo na origem e positivo no destino, ligados pelo transfer_id.
     * Transferências não têm categoria e ficam fora dos relatórios de receita e despesa.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(User $actor, array $data): TransferLegs
    {
        $transfer = TransferData::resolve($actor, $data);
        $transferId = (string) Str::uuid();

        return DB::transaction(function () use ($transfer, $transferId): TransferLegs {
            $out = $this->leg($transfer, $transferId, $transfer['from']->id, -$transfer['amount']);
            $in = $this->leg($transfer, $transferId, $transfer['to']->id, $transfer['amount']);

            return new TransferLegs($out, $in);
        });
    }

    /**
     * @param  array<string, mixed>  $transfer
     */
    private function leg(array $transfer, string $transferId, int $accountId, int $amount): Transaction
    {
        $leg = new Transaction;
        $leg->household_id = $transfer['from']->household_id;
        $leg->fill(TransferAttributes::for($transfer, $accountId, $amount) + ['transfer_id' => $transferId])->save();

        return $leg;
    }
}
