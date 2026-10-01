<?php

namespace App\Domain\Debts;

use App\Enums\TransactionStatus;
use App\Models\Debt;
use App\Models\DebtAdjustment;
use App\Models\DebtInstallment;
use App\Models\DebtPrepayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Situação da dívida (centavos). Parcela paga = lançamento dela pago.
 *
 * - saldo devedor = principal + correção (TR) das parcelas pagas − amortizações das parcelas pagas
 *   − amortizações extraordinárias + ajustes de saldo
 * - projeção: vencimento da última parcela, juros e total ainda a pagar
 */
final class DebtSummary
{
    /** @var Collection<int, DebtInstallment> */
    public readonly Collection $installments;

    /** @var array<int, bool> */
    private array $paid;

    public function __construct(public readonly Debt $debt)
    {
        $this->installments = DebtInstallment::withoutGlobalScopes()->where('debt_id', $debt->id)->with('transaction')->orderBy('number')->get();
        $this->paid = $this->installments
            ->mapWithKeys(fn (DebtInstallment $i): array => [$i->id => $i->transaction?->status === TransactionStatus::Paid])
            ->all();
    }

    public function isPaid(DebtInstallment $installment): bool
    {
        return $this->paid[$installment->id] ?? false;
    }

    public function isOverdue(DebtInstallment $installment): bool
    {
        return ! $this->isPaid($installment) && $installment->due_date->lt(today());
    }

    public function prepaid(): int
    {
        return (int) DebtPrepayment::withoutGlobalScopes()->where('debt_id', $this->debt->id)->sum('amount');
    }

    public function adjusted(): int
    {
        return (int) DebtAdjustment::withoutGlobalScopes()->where('debt_id', $this->debt->id)->sum('amount');
    }

    /**
     * Correção monetária (TR) já incorporada ao saldo pelas parcelas pagas.
     */
    public function corrected(): int
    {
        return (int) $this->installments->filter(fn (DebtInstallment $i): bool => $this->isPaid($i))->sum('correction');
    }

    public function outstanding(): int
    {
        $paid = $this->installments->filter(fn (DebtInstallment $i): bool => $this->isPaid($i));

        return max(0, $this->debt->principal + (int) $paid->sum('correction') - (int) $paid->sum('amortization') - $this->prepaid() + $this->adjusted());
    }

    public function chargesRemaining(): int
    {
        return (int) $this->unpaid()->sum('charges');
    }

    public function isPaidOff(): bool
    {
        return $this->outstanding() === 0;
    }

    public function paidCount(): int
    {
        return count(array_filter($this->paid));
    }

    /**
     * @return Collection<int, DebtInstallment>
     */
    public function unpaid(): Collection
    {
        return $this->installments->reject(fn (DebtInstallment $i): bool => $this->isPaid($i))->values();
    }

    public function next(): ?DebtInstallment
    {
        return $this->unpaid()->first();
    }

    public function payoffDate(): ?Carbon
    {
        return $this->unpaid()->last()?->due_date;
    }

    public function interestPaid(): int
    {
        return (int) $this->installments->filter(fn (DebtInstallment $i): bool => $this->isPaid($i))->sum('interest');
    }

    public function interestRemaining(): int
    {
        return (int) $this->unpaid()->sum('interest');
    }

    public function totalRemaining(): int
    {
        return (int) $this->unpaid()->sum('total');
    }
}
