<?php

use App\Domain\Accounts\AccountBalance;
use App\Domain\Accounts\UpdateAccount;
use App\Domain\Categories\DeleteCategory;
use App\Domain\Transactions\CreateTransaction;
use App\Models\Account;
use App\Models\Category;
use App\Models\Household;
use App\Models\User;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->household = Household::factory()->create();
    $this->user = User::factory()->inHousehold($this->household)->create();
    $this->expense = Category::factory()->expense()->create(['household_id' => $this->household->id]);
    $this->income = Category::factory()->income()->create(['household_id' => $this->household->id]);
});

it('saldo da conta é o saldo inicial mais a soma dos lançamentos', function () {
    $account = Account::factory()->ownedBy($this->user)->create(['initial_balance' => 100000]);
    $other = Account::factory()->ownedBy($this->user)->create(['initial_balance' => 5000]);

    $create = fn (Account $acc, Category $cat, int $amount) => app(CreateTransaction::class)->execute($this->user, [
        'account_id' => $acc->id, 'category_id' => $cat->id, 'amount' => $amount,
        'date' => '2026-09-10', 'description' => 'x',
    ]);

    $create($account, $this->income, 500000);   // +5.000,00
    $create($account, $this->expense, 123456);  // -1.234,56
    $create($account, $this->expense, 1);       // -0,01
    $create($other, $this->expense, 99999);     // outra conta

    $expected = 100000 + 500000 - 123456 - 1;
    $sum = $account->initial_balance->getMinorAmount()->toInt() + (int) $account->transactions()->sum('amount');

    expect(AccountBalance::of($account)->getMinorAmount()->toInt())->toBe($expected)
        ->and($sum)->toBe($expected);

    $withBalance = AccountBalance::addToQuery(Account::query())->get()->keyBy('id');

    expect(AccountBalance::of($withBalance[$account->id])->getMinorAmount()->toInt())->toBe($expected)
        ->and(AccountBalance::of($withBalance[$other->id])->getMinorAmount()->toInt())->toBe(5000 - 99999);
});

it('conta sem lançamentos tem o saldo inicial', function () {
    $account = Account::factory()->ownedBy($this->user)->create(['initial_balance' => -2500]);

    $loaded = AccountBalance::addToQuery(Account::query())->find($account->id);

    expect(AccountBalance::of($loaded)->getMinorAmount()->toInt())->toBe(-2500);
});

it('soma os saldos por moeda', function () {
    Account::factory()->ownedBy($this->user)->create(['initial_balance' => 1000]);
    Account::factory()->ownedBy($this->user)->create(['initial_balance' => 2000]);
    Account::factory()->ownedBy($this->user)->create(['initial_balance' => 700, 'currency' => 'USD']);

    $totals = AccountBalance::totalsByCurrency(AccountBalance::addToQuery(Account::query())->get());

    expect(array_map(fn ($m) => $m->getMinorAmount()->toInt(), $totals))->toBe(['BRL' => 3000, 'USD' => 700]);
});

it('protege conta e categoria que têm lançamentos', function () {
    $account = Account::factory()->ownedBy($this->user)->create();
    app(CreateTransaction::class)->execute($this->user, [
        'account_id' => $account->id, 'category_id' => $this->expense->id, 'amount' => 100,
        'date' => '2026-09-10', 'description' => 'x',
    ]);

    expect($this->user->can('delete', $account))->toBeFalse()
        ->and(fn () => app(UpdateAccount::class)->execute($this->user, $account, [
            'name' => $account->name, 'type' => 'checking', 'visibility' => 'private', 'currency' => 'USD', 'initial_balance' => 0,
        ]))->toThrow(ValidationException::class, 'moeda')
        ->and(fn () => app(DeleteCategory::class)->execute($this->expense))->toThrow(ValidationException::class, 'lançamentos');
});
