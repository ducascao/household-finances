<?php

namespace App\Domain\Debts;

use App\Domain\Recurrences\GenerateOccurrences;
use App\Enums\DebtStatus;
use App\Enums\TransactionStatus;
use App\Models\Debt;
use App\Models\DebtInstallment;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cria os lançamentos previstos das parcelas que vencem até hoje + 60 dias (mesma janela das contas fixas).
 * Parcela que já tem lançamento não ganha outro; atualiza a situação "quitada".
 */
class GenerateDebtInstallments
{
    public function execute(Debt $debt, ?Carbon $until = null): int
    {
        $until = ($until ?? today()->addDays(GenerateOccurrences::HORIZON_DAYS))->copy()->startOfDay();
        $created = 0;

        DB::transaction(function () use ($debt, $until, &$created): void {
            $pending = DebtInstallment::withoutGlobalScopes()
                ->where('debt_id', $debt->id)
                ->whereNull('transaction_id')
                ->whereDate('due_date', '<=', $until)
                ->orderBy('number')
                ->lockForUpdate()
                ->get();

            foreach ($pending as $installment) {
                $transaction = new Transaction;
                $transaction->household_id = $debt->household_id;
                $transaction->fill([
                    'debt_id' => $debt->id,
                    'account_id' => $debt->payment_account_id,
                    'category_id' => $debt->category_id,
                    'amount' => -$installment->total,
                    'currency' => 'BRL',
                    'status' => TransactionStatus::Scheduled,
                    'date' => $installment->due_date,
                    'due_date' => $installment->due_date,
                    'competence_date' => $installment->due_date->copy()->startOfMonth(),
                    'description' => "{$debt->name} ({$installment->number}/{$debt->installments_count})",
                    'paid_by' => $this->payer($debt),
                ])->save();

                $installment->transaction_id = $transaction->id;
                $installment->save();
                $created++;
            }

            $debt->status = (new DebtSummary($debt))->isPaidOff() ? DebtStatus::PaidOff : DebtStatus::Active;
            $debt->save();
        });

        return $created;
    }

    public function executeAll(): int
    {
        $created = 0;

        Debt::withoutGlobalScopes()->where('status', DebtStatus::Active->value)->orderBy('id')
            ->each(function (Debt $debt) use (&$created): void {
                $created += $this->execute($debt);
            });

        return $created;
    }

    private function payer(Debt $debt): int
    {
        return (int) DB::table('accounts')->where('id', $debt->payment_account_id)->value('owner_id');
    }
}
