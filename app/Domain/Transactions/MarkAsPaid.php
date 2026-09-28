<?php

namespace App\Domain\Transactions;

use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Brick\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarkAsPaid
{
    /**
     * Baixa um lançamento previsto. A data de caixa passa a ser a do pagamento (padrão: hoje);
     * o valor pode ser ajustado (informado sem sinal, o sinal do lançamento é mantido).
     * Numa transferência, as duas pernas são baixadas juntas.
     */
    public function execute(User $actor, Transaction $transaction, ?Carbon $paidAt = null, ?int $amount = null): void
    {
        if ($transaction->status === TransactionStatus::Paid) {
            throw ValidationException::withMessages(['status' => 'O lançamento já está pago.']);
        }

        if ($amount !== null && $amount === 0) {
            throw ValidationException::withMessages(['amount' => 'O valor não pode ser zero.']);
        }

        $legs = $this->legsOf($transaction);

        foreach ($legs as $leg) {
            $account = Account::withoutGlobalScopes()->find($leg->account_id);

            if ($account === null || ! $account->isVisibleTo($actor)) {
                throw ValidationException::withMessages(['account_id' => 'Sem acesso à conta deste lançamento.']);
            }
        }

        $date = ($paidAt ?? today())->copy()->startOfDay();

        DB::transaction(function () use ($legs, $date, $amount): void {
            foreach ($legs as $leg) {
                $leg->status = TransactionStatus::Paid;
                $leg->date = $date;

                if ($amount !== null) {
                    $absolute = abs($amount);
                    $leg->amount = Money::ofMinor($leg->amount->isNegative() ? -$absolute : $absolute, $leg->currency);
                }

                $leg->save();
            }
        });
    }

    /**
     * Baixa vários lançamentos na mesma data (sem ajuste de valor). Ignora os já pagos.
     *
     * @param  iterable<Transaction>  $transactions
     * @return int quantidade de lançamentos (ou transferências) baixados
     */
    public function executeMany(User $actor, iterable $transactions, ?Carbon $paidAt = null): int
    {
        $count = 0;
        $seenTransfers = [];

        foreach ($transactions as $transaction) {
            if ($transaction->status === TransactionStatus::Paid) {
                continue;
            }

            if ($transaction->transfer_id !== null) {
                if (isset($seenTransfers[$transaction->transfer_id])) {
                    continue;
                }

                $seenTransfers[$transaction->transfer_id] = true;
            }

            $this->execute($actor, $transaction, $paidAt);
            $count++;
        }

        return $count;
    }

    /**
     * @return Collection<int, Transaction>
     */
    private function legsOf(Transaction $transaction): Collection
    {
        if ($transaction->transfer_id === null) {
            return collect([$transaction]);
        }

        return Transaction::withoutGlobalScopes()
            ->where('transfer_id', $transaction->transfer_id)
            ->get();
    }
}
