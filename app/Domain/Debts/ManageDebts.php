<?php

namespace App\Domain\Debts;

use App\Domain\Investments\Quantity;
use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Enums\DebtStatus;
use App\Enums\DebtSystem;
use App\Enums\PrepaymentMode;
use App\Enums\RatePeriod;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Category;
use App\Models\Debt;
use App\Models\DebtAdjustment;
use App\Models\DebtInstallment;
use App\Models\DebtPrepayment;
use App\Models\Transaction;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Cadastro de dívidas, tabela de parcelas, amortização extraordinária, ajuste do saldo devedor e
 * correção pela TR (as parcelas ainda não pagas são recalculadas quando sai uma TR nova).
 */
class ManageDebts
{
    public function __construct(
        private readonly GenerateDebtInstallments $generator,
        private readonly ReferenceRate $tr,
    ) {}

    /**
     * Tabela calculada para a prévia do cadastro (sem gravar).
     *
     * @param  array<string, mixed>  $data
     * @return list<ScheduleRow>
     */
    public function preview(array $data): array
    {
        return $this->schedule($this->validate($data));
    }

    /**
     * @param  array<string, mixed>  $data  name, creditor, principal, rate + rate_period (ou monthly_rate), system,
     *                                      installments_count, first_due_date, payment_account_id, category_id, notes,
     *                                      tr_correction, insurance_rate, monthly_fee, rows (personalizada)
     */
    public function create(User $actor, array $data): Debt
    {
        $validated = $this->validate($data);
        $account = $this->account($actor, (int) $validated['payment_account_id']);
        $this->category($account->household_id, (int) $validated['category_id']);
        $rows = $this->schedule($validated);

        return DB::transaction(function () use ($validated, $account, $rows): Debt {
            $debt = new Debt([
                ...collect($validated)->except(['rows', 'rate', 'rate_period'])->all(),
                'installments_count' => count($rows),
                'status' => DebtStatus::Active,
            ]);
            $debt->household_id = $account->household_id;
            $debt->save();

            $this->storeRows($debt, $rows);
            $this->generator->execute($debt);

            return $debt;
        });
    }

    /**
     * Amortização extraordinária: gera o lançamento (pago) e recalcula as parcelas ainda não pagas.
     */
    public function prepay(User $actor, Debt $debt, Carbon $date, int $amount, PrepaymentMode $mode): DebtPrepayment
    {
        $account = $this->account($actor, $debt->payment_account_id);
        $summary = new DebtSummary($debt);

        if ($amount <= 0 || $amount > $summary->outstanding()) {
            throw ValidationException::withMessages(['amount' => 'O valor deve ser maior que zero e até o saldo devedor ('.number_format($summary->outstanding() / 100, 2, ',', '.').').']);
        }

        if ($date->isFuture()) {
            throw ValidationException::withMessages(['date' => 'A data não pode ser futura.']);
        }

        return DB::transaction(function () use ($actor, $debt, $account, $summary, $date, $amount, $mode): DebtPrepayment {
            $transaction = new Transaction;
            $transaction->household_id = $debt->household_id;
            $transaction->fill([
                'debt_id' => $debt->id,
                'account_id' => $account->id,
                'category_id' => $debt->category_id,
                'amount' => -$amount,
                'currency' => 'BRL',
                'status' => TransactionStatus::Paid,
                'date' => $date->copy()->startOfDay(),
                'competence_date' => $date->copy()->startOfMonth(),
                'description' => "Amortização extraordinária — {$debt->name}",
                'paid_by' => $actor->id,
            ])->save();

            $prepayment = new DebtPrepayment(['debt_id' => $debt->id, 'date' => $date->copy()->startOfDay(), 'amount' => $amount, 'mode' => $mode, 'transaction_id' => $transaction->id]);
            $prepayment->household_id = $debt->household_id;
            $prepayment->save();

            if ($debt->system !== DebtSystem::Custom) {
                $this->rebuildUnpaid($debt, $summary, $mode);
            }

            $this->generator->execute($debt);

            return $prepayment;
        });
    }

    /**
     * Só dívidas sem parcela paga nem amortização podem ser excluídas.
     */
    public function delete(User $actor, Debt $debt): void
    {
        $this->account($actor, $debt->payment_account_id);
        $summary = new DebtSummary($debt);

        if ($summary->paidCount() > 0 || $summary->prepaid() > 0) {
            throw ValidationException::withMessages(['debt' => 'A dívida tem parcelas pagas ou amortizações: não pode ser excluída.']);
        }

        DB::transaction(function () use ($debt): void {
            DebtInstallment::withoutGlobalScopes()->where('debt_id', $debt->id)->update(['transaction_id' => null]);
            Transaction::withoutGlobalScopes()->where('debt_id', $debt->id)->get()->each->delete();
            $debt->delete();
        });
    }

    /**
     * "Ajustar saldo devedor": grava a diferença para o saldo bater com o do banco e recalcula as parcelas
     * não pagas a partir dele (mesmo número de parcelas restantes).
     */
    public function adjustBalance(User $actor, Debt $debt, Carbon $date, int $balance): DebtAdjustment
    {
        $this->account($actor, $debt->payment_account_id);

        if ($debt->system === DebtSystem::Custom) {
            throw ValidationException::withMessages(['balance' => 'Na tabela informada, o saldo segue a tabela do banco.']);
        }

        if ($balance < 0) {
            throw ValidationException::withMessages(['balance' => 'Informe o saldo devedor (zero ou mais).']);
        }

        if ($date->isFuture()) {
            throw ValidationException::withMessages(['date' => 'A data não pode ser futura.']);
        }

        return DB::transaction(function () use ($debt, $date, $balance): DebtAdjustment {
            $summary = new DebtSummary($debt);
            $adjustment = new DebtAdjustment(['debt_id' => $debt->id, 'date' => $date->copy()->startOfDay(), 'amount' => $balance - $summary->outstanding()]);
            $adjustment->household_id = $debt->household_id;
            $adjustment->save();

            $this->rebuildUnpaid($debt, $summary);
            $this->generator->execute($debt);

            return $adjustment;
        });
    }

    /**
     * Recalcula as parcelas não pagas das dívidas corrigidas pela TR (depois de buscar a TR nova).
     *
     * @return int dívidas recalculadas
     */
    public function refreshTrDebts(): int
    {
        $count = 0;

        Debt::withoutGlobalScopes()->where('tr_correction', true)->where('status', DebtStatus::Active->value)->orderBy('id')
            ->each(function (Debt $debt) use (&$count): void {
                DB::transaction(fn () => $this->rebuildUnpaid($debt, new DebtSummary($debt)));
                $count++;
            });

        return $count;
    }

    /**
     * Condições de cálculo da dívida (TR pela série gravada; encargos).
     */
    public function terms(Debt $debt): DebtTerms
    {
        return new DebtTerms(
            $debt->system,
            $debt->monthly_rate,
            $debt->tr_correction ? fn (Carbon $due): BigDecimal => $this->tr->fractionFor($due) : null,
            (string) $debt->insurance_rate,
            $debt->monthly_fee,
        );
    }

    /**
     * Refaz as parcelas não pagas a partir do saldo devedor atual, mantendo numeração e vencimentos.
     * Sem modo: mesmo número de parcelas restantes. Os lançamentos previstos são atualizados, não recriados.
     */
    private function rebuildUnpaid(Debt $debt, DebtSummary $before, ?PrepaymentMode $mode = null): void
    {
        $unpaid = $before->unpaid();
        $first = $unpaid->first();

        if ($first === null) {
            return;
        }

        $balance = (new DebtSummary($debt))->outstanding();

        if ($balance === 0) {
            $debt->installments_count = $before->paidCount();
            $debt->save();
            $this->syncUnpaid($debt, []);

            return;
        }

        $terms = $this->terms($debt);
        $remaining = $unpaid->count();
        $plain = match ([$debt->system, $mode]) {
            [DebtSystem::Price, PrepaymentMode::ReduceTerm] => AmortizationSchedule::price($balance, $debt->monthly_rate, null, $first->due_date, $first->number, fixedPayment: $first->amortization + $first->interest),
            [DebtSystem::Sac, PrepaymentMode::ReduceTerm] => AmortizationSchedule::sac($balance, $debt->monthly_rate, null, $first->due_date, $first->number, fixedAmortization: $first->amortization),
            default => null,
        };

        $rows = $plain !== null && $terms->trFor === null
            ? AmortizationSchedule::withCharges($terms, $plain)
            : AmortizationSchedule::schedule($terms, $balance, $plain !== null ? count($plain) : $remaining, $first->due_date, $first->number);

        $debt->installments_count = $first->number - 1 + count($rows);
        $debt->save();
        $this->syncUnpaid($debt, $rows);
    }

    /**
     * Grava as parcelas não pagas: atualiza as existentes (e o lançamento previsto delas), cria as que faltam
     * e apaga as que sobraram. Parcelas pagas não são tocadas.
     *
     * @param  list<ScheduleRow>  $rows
     */
    private function syncUnpaid(Debt $debt, array $rows): void
    {
        $existing = DebtInstallment::withoutGlobalScopes()->where('debt_id', $debt->id)->with('transaction')->get()->keyBy('number');
        $kept = [];

        foreach ($rows as $row) {
            $installment = $existing->get($row->number) ?? new DebtInstallment(['debt_id' => $debt->id, 'number' => $row->number]);

            if ($installment->transaction?->status === TransactionStatus::Paid) {
                continue;
            }

            $kept[] = $row->number;
            $installment->household_id = $debt->household_id;
            $installment->fill($this->rowAttributes($row))->save();

            if ($installment->transaction !== null) {
                $installment->transaction->fill([
                    'amount' => -$row->total(),
                    'date' => $row->dueDate,
                    'due_date' => $row->dueDate,
                    'competence_date' => $row->dueDate->copy()->startOfMonth(),
                    'description' => "{$debt->name} ({$row->number}/{$debt->installments_count})",
                ])->save();
            }
        }

        foreach ($existing as $number => $installment) {
            if (in_array($number, $kept, true) || $installment->transaction?->status === TransactionStatus::Paid) {
                continue;
            }

            $transaction = $installment->transaction;
            $installment->delete();
            $transaction?->delete();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function rowAttributes(ScheduleRow $row): array
    {
        return [
            'due_date' => $row->dueDate,
            'correction' => $row->correction,
            'amortization' => $row->amortization,
            'interest' => $row->interest,
            'charges' => $row->charges,
            'total' => $row->total(),
            'balance_after' => $row->balanceAfter,
        ];
    }

    /**
     * @param  list<ScheduleRow>  $rows
     */
    private function storeRows(Debt $debt, array $rows): void
    {
        foreach ($rows as $row) {
            $installment = new DebtInstallment(['debt_id' => $debt->id, 'number' => $row->number, ...$this->rowAttributes($row)]);
            $installment->household_id = $debt->household_id;
            $installment->save();
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<ScheduleRow>
     */
    private function schedule(array $validated): array
    {
        $firstDue = Carbon::parse($validated['first_due_date']);

        $system = DebtSystem::from($validated['system']);

        try {
            return $system === DebtSystem::Custom
                ? $this->customRows($validated)
                : AmortizationSchedule::schedule(new DebtTerms(
                    $system,
                    $validated['monthly_rate'],
                    $validated['tr_correction'] ? fn (Carbon $due): BigDecimal => $this->tr->fractionFor($due) : null,
                    $validated['insurance_rate'],
                    $validated['monthly_fee'],
                ), $validated['principal'], $validated['installments_count'], $firstDue);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['installments_count' => $e->getMessage()]);
        }
    }

    /**
     * Tabela informada: a soma das amortizações precisa fechar o principal.
     *
     * @param  array<string, mixed>  $validated
     * @return list<ScheduleRow>
     */
    private function customRows(array $validated): array
    {
        /** @var list<array{due_date: string, amortization: int|string, interest: int|string}> $input */
        $input = array_values($validated['rows'] ?? []);

        if ($input === []) {
            throw ValidationException::withMessages(['rows' => 'Informe as parcelas da tabela.']);
        }

        $balance = (int) $validated['principal'];
        $rows = [];

        foreach ($input as $index => $row) {
            $amortization = (int) $row['amortization'];
            $interest = (int) $row['interest'];
            $balance -= $amortization;
            $rows[] = new ScheduleRow($index + 1, Carbon::parse($row['due_date'])->startOfDay(), $amortization, $interest, $balance);
        }

        if ($balance !== 0) {
            throw ValidationException::withMessages(['rows' => 'A soma das amortizações precisa ser igual ao valor financiado (diferença de '.number_format($balance / 100, 2, ',', '.').').']);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data): array
    {
        $data['system'] = ($data['system'] ?? null) instanceof DebtSystem ? $data['system']->value : ($data['system'] ?? null);
        $period = ($data['rate_period'] ?? null) instanceof RatePeriod ? $data['rate_period'] : (RatePeriod::tryFrom((string) ($data['rate_period'] ?? '')) ?? RatePeriod::Monthly);
        $rate = array_key_exists('rate', $data) ? $data['rate'] : ($data['monthly_rate'] ?? null);

        try {
            $data['monthly_rate'] = $period->toMonthly(Quantity::parse($rate));
        } catch (InvalidArgumentException) {
            $data['monthly_rate'] = 'inválida';
        }

        try {
            $data['insurance_rate'] = filled($data['insurance_rate'] ?? null) ? Quantity::parse($data['insurance_rate']) : '0';
        } catch (InvalidArgumentException) {
            $data['insurance_rate'] = 'inválida';
        }

        $data['monthly_fee'] = $data['monthly_fee'] ?? 0;
        $data['tr_correction'] = (bool) ($data['tr_correction'] ?? false);
        $custom = $data['system'] === DebtSystem::Custom->value;

        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'creditor' => ['required', 'string', 'max:255'],
            'principal' => ['required', 'integer', 'min:1'],
            'monthly_rate' => ['required', 'numeric', 'min:0', 'max:20'],
            'insurance_rate' => ['required', 'numeric', 'min:0', 'max:5'],
            'monthly_fee' => ['required', 'integer', 'min:0'],
            'tr_correction' => ['boolean'],
            'system' => ['required', Rule::enum(DebtSystem::class)],
            'installments_count' => [$custom ? 'nullable' : 'required', 'integer', 'between:1,600'],
            'first_due_date' => ['required', 'date'],
            'payment_account_id' => ['required', 'integer'],
            'category_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string'],
            'rows' => [$custom ? 'required' : 'nullable', 'array'],
            'rows.*.due_date' => ['required', 'date'],
            'rows.*.amortization' => ['required', 'integer', 'min:0'],
            'rows.*.interest' => ['required', 'integer', 'min:0'],
        ], attributes: [
            'name' => 'nome', 'creditor' => 'credor', 'principal' => 'valor financiado', 'monthly_rate' => 'taxa ao mês',
            'system' => 'sistema', 'installments_count' => 'número de parcelas', 'first_due_date' => '1º vencimento',
            'payment_account_id' => 'conta de pagamento', 'category_id' => 'categoria', 'rows' => 'parcelas',
            'insurance_rate' => 'seguro (% do saldo)', 'monthly_fee' => 'encargos fixos',
        ])->validate();

        $validated['monthly_rate'] = (string) BigDecimal::of((string) $validated['monthly_rate'])->toScale(8);
        $validated['insurance_rate'] = (string) BigDecimal::of((string) $validated['insurance_rate'])->toScale(8);
        $validated['monthly_fee'] = (int) $validated['monthly_fee'];
        $validated['tr_correction'] = (bool) ($validated['tr_correction'] ?? false);

        if ($custom && $validated['tr_correction']) {
            throw ValidationException::withMessages(['tr_correction' => 'Na tabela informada, a correção já vem na tabela do banco.']);
        }
        $validated['principal'] = (int) $validated['principal'];
        $validated['installments_count'] = isset($validated['installments_count']) ? (int) $validated['installments_count'] : 0;

        return $validated;
    }

    private function account(User $actor, int $accountId): Account
    {
        $account = Account::withoutGlobalScopes()->find($accountId);

        if ($account === null || ! $account->isVisibleTo($actor)) {
            throw ValidationException::withMessages(['payment_account_id' => 'Conta não encontrada.']);
        }

        if (! in_array($account->type, [AccountType::Checking, AccountType::Savings, AccountType::Cash], true) || $account->currency !== 'BRL') {
            throw ValidationException::withMessages(['payment_account_id' => 'Escolha uma conta corrente, poupança ou dinheiro, em reais.']);
        }

        return $account;
    }

    private function category(int $householdId, int $categoryId): Category
    {
        $category = Category::withoutGlobalScopes()->where('household_id', $householdId)->find($categoryId);

        if ($category === null || $category->type !== CategoryType::Expense) {
            throw ValidationException::withMessages(['category_id' => 'Escolha uma categoria de despesa.']);
        }

        return $category;
    }
}
