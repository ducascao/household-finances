<?php

use App\Domain\Accounts\AccountBalance;
use App\Domain\Transactions\CreateTransaction;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Category;
use App\Models\Household;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');

    $this->household = Household::factory()->create();
    $this->user = User::factory()->inHousehold($this->household)->create();
    $this->account = Account::factory()->ownedBy($this->user)->create(['initial_balance' => 100000]);
    $this->expense = Category::factory()->expense()->create(['household_id' => $this->household->id]);
    $this->income = Category::factory()->income()->create(['household_id' => $this->household->id]);

    $this->create = fn (array $data) => app(CreateTransaction::class)->execute($this->user, [
        'account_id' => $this->account->id,
        'category_id' => $this->expense->id,
        'amount' => 1000,
        'description' => 'x',
        ...$data,
    ]);
});

it('lançamento previsto usa o vencimento como data e competência', function () {
    $transaction = ($this->create)(['status' => 'scheduled', 'due_date' => '2026-10-05']);

    expect($transaction->status)->toBe(TransactionStatus::Scheduled)
        ->and($transaction->date->toDateString())->toBe('2026-10-05')
        ->and($transaction->due_date->toDateString())->toBe('2026-10-05')
        ->and($transaction->competence_date->toDateString())->toBe('2026-10-01');
});

it('lançamento previsto exige vencimento', function () {
    ($this->create)(['status' => 'scheduled', 'date' => '2026-10-05']);
})->throws(ValidationException::class);

it('sem status o lançamento é pago', function () {
    expect(($this->create)(['date' => '2026-09-10'])->status)->toBe(TransactionStatus::Paid);
});

it('previsto vencido e não pago aparece como atrasado', function () {
    $overdue = ($this->create)(['status' => 'scheduled', 'due_date' => '2026-09-19']);
    $dueToday = ($this->create)(['status' => 'scheduled', 'due_date' => '2026-09-20']);
    $paidLate = ($this->create)(['date' => '2026-09-15', 'due_date' => '2026-09-10']);

    expect($overdue->isOverdue())->toBeTrue()
        ->and($dueToday->isOverdue())->toBeFalse()
        ->and($paidLate->isOverdue())->toBeFalse()
        ->and(Transaction::overdue()->pluck('id')->all())->toBe([$overdue->id]);
});

it('saldo atual considera só os pagos e o projetado inclui previstos até o fim do mês', function () {
    ($this->create)(['date' => '2026-09-10', 'amount' => 20000]);                                   // pago: -200
    ($this->create)(['status' => 'scheduled', 'due_date' => '2026-09-15', 'amount' => 5000]);       // atrasado: -50
    ($this->create)(['status' => 'scheduled', 'due_date' => '2026-09-30', 'amount' => 3000]);       // no mês: -30
    ($this->create)(['status' => 'scheduled', 'due_date' => '2026-09-25', 'category_id' => $this->income->id, 'amount' => 40000]); // +400
    ($this->create)(['status' => 'scheduled', 'due_date' => '2026-10-01', 'amount' => 99900]);      // mês seguinte: fora

    $current = 100000 - 20000;
    $projected = $current - 5000 - 3000 + 40000;

    expect(AccountBalance::of($this->account)->getMinorAmount()->toInt())->toBe($current)
        ->and(AccountBalance::projectedOf($this->account)->getMinorAmount()->toInt())->toBe($projected);

    $loaded = AccountBalance::addToQuery(Account::query())->find($this->account->id);

    expect(AccountBalance::of($loaded)->getMinorAmount()->toInt())->toBe($current)
        ->and(AccountBalance::projectedOf($loaded)->getMinorAmount()->toInt())->toBe($projected);
});
