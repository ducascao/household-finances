<?php

use App\Filament\Resources\Transactions\Pages\EditTransaction;
use App\Filament\Resources\Transactions\Pages\ListTransactions;
use App\Filament\Widgets\AccountBalancesWidget;
use App\Models\Account;
use App\Models\Category;
use App\Models\Household;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->household = Household::factory()->create();
    $this->userA = User::factory()->withTwoFactor()->inHousehold($this->household)->create();
    $this->userB = User::factory()->withTwoFactor()->inHousehold($this->household)->create();

    $this->category = Category::factory()->expense()->create(['household_id' => $this->household->id]);

    $this->accountOfB = Account::factory()->ownedBy($this->userB)->private()->create(['name' => 'Conta do B']);
    $this->shared = Account::factory()->ownedBy($this->userB)->shared()->create(['name' => 'Conjunta']);
    $this->accountOfA = Account::factory()->ownedBy($this->userA)->private()->create(['name' => 'Conta da A']);

    $this->txOfB = Transaction::factory()->forAccount($this->accountOfB)->withCategory($this->category, 1000)->create();
    $this->txShared = Transaction::factory()->forAccount($this->shared)->withCategory($this->category, 2000)->create();
    $this->txOfA = Transaction::factory()->forAccount($this->accountOfA)->withCategory($this->category, 3000)->create();
});

it('usuário A não vê lançamentos da conta privada do usuário B', function () {
    $this->actingAs($this->userA);

    expect(Transaction::pluck('id')->all())->toEqualCanonicalizing([$this->txShared->id, $this->txOfA->id])
        ->and($this->userA->can('view', $this->txOfB))->toBeFalse()
        ->and($this->userA->can('update', $this->txOfB))->toBeFalse();

    Livewire::test(ListTransactions::class)
        ->assertCanSeeTableRecords([$this->txShared, $this->txOfA])
        ->assertCanNotSeeTableRecords([$this->txOfB]);

    $this->get(route('filament.app.resources.transactions.edit', $this->txOfB))->assertNotFound();
});

it('ambos veem e editam lançamentos da conta compartilhada', function (string $who) {
    $user = $this->{$who};
    $this->actingAs($user);

    expect($user->can('update', $this->txShared))->toBeTrue();

    Livewire::test(EditTransaction::class, ['record' => $this->txShared->getRouteKey()])
        ->assertFormSet(['amount' => '20,00'])
        ->fillForm(['description' => "Editado por {$who}", 'amount' => '25,00'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->txShared->refresh())
        ->description->toBe("Editado por {$who}")
        ->and($this->txShared->amount->getMinorAmount()->toInt())->toBe(-2500);
})->with(['userA', 'userB']);

it('o widget de saldos mostra só contas visíveis', function () {
    $this->actingAs($this->userA);

    Livewire::test(AccountBalancesWidget::class)
        ->assertCanSeeTableRecords([$this->shared, $this->accountOfA])
        ->assertCanNotSeeTableRecords([$this->accountOfB])
        ->assertSee('-R$ 30,00')
        ->assertDontSee('Conta do B');
});

it('filtra por conta e por pago por', function () {
    $this->actingAs($this->userA);

    Livewire::test(ListTransactions::class)
        ->filterTable('account_id', [$this->shared->id])
        ->assertCanSeeTableRecords([$this->txShared])
        ->assertCanNotSeeTableRecords([$this->txOfA])
        ->resetTableFilters()
        ->filterTable('paid_by', $this->userA->id)
        ->assertCanSeeTableRecords([$this->txOfA])
        ->assertCanNotSeeTableRecords([$this->txShared]);
});

it('filtra por período e pela categoria principal incluindo as filhas', function () {
    $child = Category::factory()->childOf($this->category)->create();
    $other = Category::factory()->expense()->create(['household_id' => $this->household->id]);
    $inChild = Transaction::factory()->forAccount($this->accountOfA)->withCategory($child, 100)->create(['date' => '2026-01-10']);
    $inOther = Transaction::factory()->forAccount($this->accountOfA)->withCategory($other, 100)->create(['date' => '2026-01-20']);

    $this->actingAs($this->userA);

    Livewire::test(ListTransactions::class)
        ->filterTable('category_id', $this->category->id)
        ->assertCanSeeTableRecords([$inChild, $this->txOfA])
        ->assertCanNotSeeTableRecords([$inOther])
        ->resetTableFilters()
        ->filterTable('period', ['from' => '2026-01-01', 'until' => '2026-01-15'])
        ->assertCanSeeTableRecords([$inChild])
        ->assertCanNotSeeTableRecords([$inOther]);
});

it('cria pelo lançamento rápido', function () {
    $this->actingAs($this->userA);

    Livewire::test(ListTransactions::class)
        ->callAction('quickCreate', [
            'account_id' => $this->accountOfA->id,
            'category_id' => $this->category->id,
            'amount' => '1.234,56',
            'date' => '2026-09-20',
            'description' => 'Padaria',
        ])
        ->assertHasNoActionErrors();

    expect(Transaction::where('description', 'Padaria')->sole())
        ->paid_by->toBe($this->userA->id)
        ->and(Transaction::where('description', 'Padaria')->sole()->amount->getMinorAmount()->toInt())->toBe(-123456);
});
