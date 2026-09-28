<?php

namespace App\Domain\CreditCard;

use App\Domain\Transactions\TransactionData;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\CreditCard;
use App\Models\InstallmentGroup;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreateInstallmentPurchase
{
    public function __construct(
        private readonly AssignTransactionToInvoice $assign,
    ) {}

    /**
     * Compra parcelada no cartão: N lançamentos, a parcela k na fatura da compra + (k−1) meses,
     * com "k/N" na descrição. A última parcela absorve o arredondamento (1.000,00 em 3x = 333,33 + 333,33 + 333,34).
     *
     * @param  array<string, mixed>  $data  campos do lançamento + installments; amount = valor total
     */
    public function execute(User $actor, array $data): InstallmentGroup
    {
        $count = (int) Validator::make($data, [
            'installments' => ['required', 'integer', 'between:2,72'],
        ], attributes: ['installments' => 'parcelas'])->validate()['installments'];

        $entry = TransactionData::resolve($actor, [...$data, 'status' => TransactionStatus::Paid->value, 'is_refund' => false]);

        $account = Account::withoutGlobalScopes()->findOrFail($entry['account_id']);
        $card = CreditCard::withoutGlobalScopes()->where('account_id', $account->id)->first();

        if ($card === null) {
            throw ValidationException::withMessages(['account_id' => 'Compra parcelada só em conta de cartão de crédito.']);
        }

        $total = abs($entry['amount']);
        $sign = $entry['amount'] < 0 ? -1 : 1;

        if ($total < $count) {
            throw ValidationException::withMessages(['amount' => 'Valor menor que o número de parcelas.']);
        }

        $installment = intdiv($total, $count);

        return DB::transaction(function () use ($entry, $card, $count, $total, $sign, $installment): InstallmentGroup {
            $group = new InstallmentGroup([
                'account_id' => $entry['account_id'],
                'category_id' => $entry['category_id'],
                'total_amount' => $sign * $total,
                'currency' => $entry['currency'],
                'installments' => $count,
                'description' => $entry['description'],
                'purchase_date' => $entry['date'],
            ]);
            $group->household_id = $entry['household_id'];
            $group->save();

            for ($number = 1; $number <= $count; $number++) {
                $amount = $number < $count ? $installment : $total - $installment * ($count - 1);
                $invoice = $this->assign->invoiceForPurchase($card, $entry['date'], $number - 1);

                $transaction = new Transaction;
                $transaction->household_id = $entry['household_id'];
                $transaction->fill([
                    'account_id' => $entry['account_id'],
                    'category_id' => $entry['category_id'],
                    'amount' => $sign * $amount,
                    'currency' => $entry['currency'],
                    'status' => TransactionStatus::Paid,
                    'date' => $entry['date']->copy()->addMonthsNoOverflow($number - 1),
                    'competence_date' => $invoice->reference_month->copy(),
                    'description' => "{$entry['description']} ({$number}/{$count})",
                    'paid_by' => $entry['paid_by'],
                    'notes' => $entry['notes'],
                    'tags' => $entry['tags'],
                    'invoice_id' => $invoice->id,
                    'installment_group_id' => $group->id,
                    'installment_number' => $number,
                ])->save();
            }

            return $group;
        });
    }
}
