<?php

use App\Domain\Transactions\CreateTransaction;
use App\Domain\Transactions\MarkAsPaid;
use App\Enums\TransactionStatus;
use App\Filament\Resources\Transactions\Pages\CreateTransaction as CreateTransactionPage;
use App\Filament\Resources\Transactions\Pages\ListTransactions;
use App\Models\Account;
use App\Models\Category;
use App\Models\Household;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');

    $this->household = Household::factory()->create();
    $this->user = User::factory()->withTwoFactor()->inHousehold($this->household)->create();
    $this->other = User::factory()->withTwoFactor()->inHousehold($this->household)->create();
    $this->account = Account::factory()->ownedBy($this->user)->create();
    $this->expense = Category::factory()->expense()->create(['household_id' => $this->household->id]);
    $this->income = Category::factory()->income()->create(['household_id' => $this->household->id]);

    $this->scheduled = fn (array $data = []) => app(CreateTransaction::class)->execute($this->user, [
        'account_id' => $this->account->id,
        'category_id' => $this->expense->id,
        'amount' => 15000,
        'status' => 'scheduled',
        'due_date' => '2026-09-10',
        'description' => 'Luz',
        ...$data,
    ]);
});

it('marca como pago ajustando data e valor e mantendo o sinal', function () {
    $bill = ($this->scheduled)();
    $salary = ($this->scheduled)(['category_id' => $this->income->id, 'amount' => 500000]);

    app(MarkAsPaid::class)->execute($this->user, $bill, Carbon::parse('2026-09-12'), 16234);
    app(MarkAsPaid::class)->execute($this->user, $salary, null, 510000);

    expect($bill->refresh())
        ->status->toBe(TransactionStatus::Paid)
        ->and($bill->date->toDateString())->toBe('2026-09-12')
        ->and($bill->due_date->toDateString())->toBe('2026-09-10')
        ->and($bill->amount->getMinorAmount()->toInt())->toBe(-16234)
        ->and($bill->isOverdue())->toBeFalse()
        ->and($salary->refresh()->date->toDateString())->toBe('2026-09-20')
        ->and($salary->amount->getMinorAmount()->toInt())->toBe(510000);
});

it('não paga duas vezes', function () {
    $bill = ($this->scheduled)();
    app(MarkAsPaid::class)->execute($this->user, $bill);

    app(MarkAsPaid::class)->execute($this->user, $bill->refresh());
})->throws(ValidationException::class, 'já está pago');

it('não paga lançamento de conta privada de outro usuário', function () {
    $bill = ($this->scheduled)();

    app(MarkAsPaid::class)->execute($this->other, $bill);
})->throws(ValidationException::class);

it('paga pela ação da linha com data e valor', function () {
    $bill = ($this->scheduled)();
    $this->actingAs($this->user);

    Livewire::test(ListTransactions::class)
        ->callTableAction('markAsPaid', $bill, ['paid_at' => '2026-09-15', 'amount' => '149,90'])
        ->assertHasNoTableActionErrors();

    expect($bill->refresh())
        ->status->toBe(TransactionStatus::Paid)
        ->and($bill->date->toDateString())->toBe('2026-09-15')
        ->and($bill->amount->getMinorAmount()->toInt())->toBe(-14990);
});

it('paga em massa só com a data, mantendo os valores', function () {
    $a = ($this->scheduled)(['amount' => 1000]);
    $b = ($this->scheduled)(['amount' => 2000]);
    $alreadyPaid = app(CreateTransaction::class)->execute($this->user, [
        'account_id' => $this->account->id, 'category_id' => $this->expense->id,
        'amount' => 3000, 'date' => '2026-09-01', 'description' => 'já pago',
    ]);
    $this->actingAs($this->user);

    Livewire::test(ListTransactions::class)
        ->callTableBulkAction('markAsPaid', [$a, $b, $alreadyPaid], ['paid_at' => '2026-09-18'])
        ->assertHasNoTableBulkActionErrors();

    expect(Transaction::whereKey([$a->id, $b->id])->pluck('date')->map->toDateString()->unique()->all())->toBe(['2026-09-18'])
        ->and($a->refresh()->amount->getMinorAmount()->toInt())->toBe(-1000)
        ->and($b->refresh()->status)->toBe(TransactionStatus::Paid)
        ->and($alreadyPaid->refresh()->date->toDateString())->toBe('2026-09-01');
});

it('filtra só os atrasados', function () {
    $overdue = ($this->scheduled)(['due_date' => '2026-09-10']);
    $future = ($this->scheduled)(['due_date' => '2026-09-25']);
    $this->actingAs($this->user);

    Livewire::test(ListTransactions::class)
        ->filterTable('overdue')
        ->assertCanSeeTableRecords([$overdue])
        ->assertCanNotSeeTableRecords([$future]);
});

it('cria lançamento previsto pelo formulário', function () {
    $this->actingAs($this->user);

    Livewire::test(CreateTransactionPage::class)
        ->fillForm([
            'account_id' => $this->account->id,
            'category_id' => $this->expense->id,
            'amount' => '89,90',
            'status' => 'scheduled',
            'due_date' => '2026-10-05',
            'description' => 'Internet',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Transaction::where('description', 'Internet')->sole())
        ->status->toBe(TransactionStatus::Scheduled)
        ->and(Transaction::where('description', 'Internet')->sole()->date->toDateString())->toBe('2026-10-05');
});
