<?php

use App\Enums\AccountVisibility;
use App\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Models\Account;
use App\Models\Household;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->household = Household::factory()->create();
    $this->userA = User::factory()->withTwoFactor()->inHousehold($this->household)->create();
    $this->userB = User::factory()->withTwoFactor()->inHousehold($this->household)->create();

    $this->privateOfA = Account::factory()->ownedBy($this->userA)->create(['name' => 'Conta da A']);
    $this->privateOfB = Account::factory()->ownedBy($this->userB)->create(['name' => 'Conta do B']);
    $this->shared = Account::factory()->ownedBy($this->userB)->shared()->create(['name' => 'Conjunta']);
});

it('lista só as contas visíveis ao usuário', function () {
    $this->actingAs($this->userA);

    Livewire::test(ListAccounts::class)
        ->assertCanSeeTableRecords([$this->privateOfA, $this->shared])
        ->assertCanNotSeeTableRecords([$this->privateOfB]);
});

it('não abre a edição da conta privada de outro usuário', function () {
    $this->actingAs($this->userA);

    $this->get(route('filament.app.resources.accounts.edit', $this->privateOfB))->assertNotFound();
});

it('usuário que não é dono edita a conta compartilhada', function () {
    $this->actingAs($this->userA);

    Livewire::test(EditAccount::class, ['record' => $this->shared->getRouteKey()])
        ->fillForm(['name' => 'Conjunta Itaú', 'initial_balance' => '1.500,00'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->shared->refresh())
        ->name->toBe('Conjunta Itaú')
        ->and($this->shared->initial_balance->getMinorAmount()->toInt())->toBe(150000);
});

it('cria conta pelo formulário com o usuário logado como dono', function () {
    $this->actingAs($this->userA);

    Livewire::test(CreateAccount::class)
        ->fillForm([
            'name' => 'Carteira',
            'type' => 'cash',
            'visibility' => 'shared',
            'currency' => 'BRL',
            'initial_balance' => '250,50',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $account = Account::where('name', 'Carteira')->sole();

    expect($account->owner_id)->toBe($this->userA->id)
        ->and($account->visibility)->toBe(AccountVisibility::Shared)
        ->and($account->initial_balance->getMinorAmount()->toInt())->toBe(25050);
});
