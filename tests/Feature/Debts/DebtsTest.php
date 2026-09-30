<?php

use App\Domain\Debts\DebtSummary;
use App\Domain\Debts\GenerateDebtInstallments;
use App\Domain\Debts\ManageDebts;
use App\Domain\Household\CreateHousehold;
use App\Domain\Transactions\BillsSummary;
use App\Domain\Transactions\DeleteTransaction;
use App\Domain\Transactions\MarkAsPaid;
use App\Domain\Transactions\UpdateTransaction;
use App\Enums\DebtStatus;
use App\Enums\PrepaymentMode;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Category;
use App\Models\Debt;
use App\Models\DebtInstallment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Carbon::setTestNow('2026-01-01 10:00:00');

    $household = app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $this->user = User::where('email', 'eduardo@example.com')->sole();
    $this->maria = User::factory()->inHousehold($household)->create();
    $this->joint = Account::factory()->ownedBy($this->user)->shared()->create(['name' => 'Conjunta']);
    $this->personal = Account::factory()->ownedBy($this->user)->private()->create(['name' => 'Pessoal']);
    $this->category = Category::where('household_id', $household->id)->where('name', 'Moradia')->sole();
    $this->debts = app(ManageDebts::class);

    $this->create = fn (array $data = []) => $this->debts->create($this->user, [
        'name' => 'Empréstimo', 'creditor' => 'Banco X', 'principal' => 1000000, 'monthly_rate' => '1',
        'system' => 'price', 'installments_count' => 12, 'first_due_date' => '2026-01-15',
        'payment_account_id' => $this->joint->id, 'category_id' => $this->category->id, ...$data,
    ]);
    $this->pay = function (Debt $debt, int $upTo): void {
        DebtInstallment::where('debt_id', $debt->id)->where('number', '<=', $upTo)->orderBy('number')->get()
            ->each(function (DebtInstallment $installment) use ($debt): void {
                Carbon::setTestNow($installment->due_date->copy()->setTime(10, 0));
                app(GenerateDebtInstallments::class)->execute($debt);
                app(MarkAsPaid::class)->execute($this->user, Transaction::findOrFail($installment->refresh()->transaction_id));
            });
    };
});

it('cria a tabela e os lançamentos previstos só dos próximos 60 dias, sem duplicar', function () {
    $debt = ($this->create)();

    expect(DebtInstallment::where('debt_id', $debt->id)->count())->toBe(12)
        ->and(Transaction::where('debt_id', $debt->id)->count())->toBe(2) // 15/01 e 15/02 (≤ 02/03)
        ->and(Transaction::where('debt_id', $debt->id)->orderBy('date')->first())
        ->status->toBe(TransactionStatus::Scheduled)
        ->description->toBe('Empréstimo (1/12)');

    app(GenerateDebtInstallments::class)->executeAll();
    expect(Transaction::where('debt_id', $debt->id)->count())->toBe(2);
});

it('pagar o lançamento baixa a parcela e reduz o saldo devedor', function () {
    $debt = ($this->create)();
    ($this->pay)($debt, 2);

    $summary = new DebtSummary($debt);

    // amortizações: 788,49 + 796,37
    expect($summary->paidCount())->toBe(2)
        ->and($summary->outstanding())->toBe(1000000 - 78849 - 79637)
        ->and($summary->outstanding())->toBe(DebtInstallment::where('debt_id', $debt->id)->where('number', 2)->value('balance_after'))
        ->and($summary->interestPaid())->toBe(10000 + 9212)
        ->and($summary->next()->number)->toBe(3)
        ->and($summary->payoffDate()->toDateString())->toBe('2026-12-15');
});

it('parcela vencida e não paga aparece como atrasada no painel', function () {
    $debt = ($this->create)();
    Carbon::setTestNow('2026-01-20 10:00:00');
    $this->actingAs($this->user);

    $installment = DebtInstallment::where('debt_id', $debt->id)->where('number', 1)->sole();

    expect((new DebtSummary($debt))->isOverdue($installment))->toBeTrue()
        ->and(app(BillsSummary::class)->overdue()['count'])->toBe(1);
});

it('amortização extraordinária na Price reduzindo a parcela mantém o prazo', function () {
    $debt = ($this->create)();
    ($this->pay)($debt, 2);
    $before = (new DebtSummary($debt))->outstanding(); // 8.415,14

    $this->debts->prepay($this->user, $debt, Carbon::parse('2026-02-15'), 300000, PrepaymentMode::ReduceInstallment);
    $summary = new DebtSummary($debt->refresh());
    $unpaid = $summary->unpaid();

    expect($summary->outstanding())->toBe($before - 300000)
        ->and($unpaid)->toHaveCount(10)
        ->and($unpaid->first()->number)->toBe(3)
        ->and($unpaid->first()->due_date->toDateString())->toBe('2026-03-15')
        ->and($unpaid->first()->total)->toBeLessThan(88849)
        ->and($unpaid->last()->balance_after)->toBe(0)
        ->and($unpaid->sum('amortization'))->toBe($before - 300000)
        ->and($summary->paidCount())->toBe(2)
        ->and($debt->installments_count)->toBe(12)
        ->and(Transaction::where('debt_id', $debt->id)->where('description', 'like', 'Amortização extraordinária%')->sole()->amount->getMinorAmount()->toInt())->toBe(-300000);
});

it('amortização extraordinária na Price reduzindo o prazo mantém a parcela', function () {
    $debt = ($this->create)();
    ($this->pay)($debt, 2);

    $this->debts->prepay($this->user, $debt, Carbon::parse('2026-02-15'), 300000, PrepaymentMode::ReduceTerm);
    $unpaid = (new DebtSummary($debt->refresh()))->unpaid();

    expect($unpaid->count())->toBeLessThan(10)
        ->and($unpaid->first()->total)->toBe(88849)
        ->and($unpaid->last()->balance_after)->toBe(0)
        ->and($debt->installments_count)->toBe(2 + $unpaid->count());
});

it('amortização extraordinária no SAC nos dois modos', function (PrepaymentMode $mode, int $expectedCount, int $expectedAmortization) {
    $debt = ($this->create)(['system' => 'sac']);
    ($this->pay)($debt, 2);
    // saldo 10.000 − 2 × 833,33 = 8.333,34; − 2.000,00 = 6.333,34

    $this->debts->prepay($this->user, $debt, Carbon::parse('2026-02-15'), 200000, $mode);
    $unpaid = (new DebtSummary($debt->refresh()))->unpaid();

    expect($unpaid)->toHaveCount($expectedCount)
        ->and($unpaid->first()->amortization)->toBe($expectedAmortization)
        ->and($unpaid->sum('amortization'))->toBe(633334)
        ->and($unpaid->last()->balance_after)->toBe(0);
})->with([
    'reduzindo a parcela' => [PrepaymentMode::ReduceInstallment, 10, 63333],  // 6.333,34 ÷ 10
    'reduzindo o prazo' => [PrepaymentMode::ReduceTerm, 8, 83333],           // 6.333,34 ÷ 833,33 = 7,6 → 8
]);

it('quitar com amortização zera o saldo e marca como quitada', function () {
    $debt = ($this->create)();
    $this->debts->prepay($this->user, $debt, Carbon::parse('2026-01-01'), 1000000, PrepaymentMode::ReduceTerm);

    expect((new DebtSummary($debt->refresh()))->outstanding())->toBe(0)
        ->and($debt->status)->toBe(DebtStatus::PaidOff)
        ->and(Transaction::where('debt_id', $debt->id)->where('status', 'scheduled')->count())->toBe(0);
});

it('tabela personalizada precisa fechar o principal', function () {
    $rows = [
        ['due_date' => '2026-01-10', 'amortization' => 500000, 'interest' => 10000],
        ['due_date' => '2026-02-10', 'amortization' => 500000, 'interest' => 5000],
    ];
    $debt = ($this->create)(['system' => 'custom', 'installments_count' => null, 'rows' => $rows]);

    expect(DebtInstallment::where('debt_id', $debt->id)->pluck('total')->all())->toBe([510000, 505000])
        ->and(fn () => ($this->create)(['system' => 'custom', 'rows' => [['due_date' => '2026-01-10', 'amortization' => 1, 'interest' => 0]]]))
        ->toThrow(ValidationException::class, 'soma das amortizações');
});

it('lançamento de parcela só é alterado pela dívida; dívida com parcela paga não é excluída', function () {
    $debt = ($this->create)();
    $transaction = Transaction::where('debt_id', $debt->id)->orderBy('date')->first();

    expect(fn () => app(UpdateTransaction::class)->execute($this->user, $transaction, ['description' => 'x']))->toThrow(ValidationException::class, 'tela da dívida')
        ->and(fn () => app(DeleteTransaction::class)->execute($this->user, $transaction))->toThrow(ValidationException::class, 'tela da dívida');

    ($this->pay)($debt, 1);
    expect(fn () => $this->debts->delete($this->user, $debt))->toThrow(ValidationException::class, 'não pode ser excluída');

    $other = ($this->create)(['name' => 'Outra']);
    $this->debts->delete($this->user, $other);
    expect(Debt::find($other->id))->toBeNull()->and(Transaction::where('debt_id', $other->id)->count())->toBe(0);
});

it('valida conta, categoria e amortização acima do saldo', function () {
    $card = Account::factory()->ownedBy($this->user)->create(['type' => 'credit_card']);
    $income = Category::where('name', 'Salário')->first();
    $debt = ($this->create)();

    expect(fn () => ($this->create)(['payment_account_id' => $card->id]))->toThrow(ValidationException::class, 'conta corrente')
        ->and(fn () => ($this->create)(['category_id' => $income->id]))->toThrow(ValidationException::class, 'despesa')
        ->and(fn () => $this->debts->prepay($this->user, $debt, Carbon::parse('2026-01-01'), 1000001, PrepaymentMode::ReduceTerm))->toThrow(ValidationException::class, 'saldo devedor');
});

it('dívida em conta privada não aparece para o outro usuário', function () {
    $private = ($this->create)(['payment_account_id' => $this->personal->id, 'name' => 'Pessoal']);
    ($this->create)(['name' => 'Da casa']);

    $this->actingAs($this->maria);

    expect(Debt::pluck('name')->all())->toBe(['Da casa'])
        ->and(fn () => $this->debts->prepay($this->maria, $private, Carbon::parse('2026-01-01'), 100, PrepaymentMode::ReduceTerm))->toThrow(ValidationException::class);
});
