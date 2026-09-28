<?php

use App\Domain\Accounts\ArchiveAccount;
use App\Domain\Accounts\CreateAccount;
use App\Domain\Accounts\UpdateAccount;
use App\Enums\AccountType;
use App\Enums\AccountVisibility;
use App\Models\Account;
use App\Models\Household;
use App\Models\User;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->household = Household::factory()->create();
    $this->owner = User::factory()->inHousehold($this->household)->create();
    $this->other = User::factory()->inHousehold($this->household)->create();
});

it('cria conta com o usuário como dono e no lar dele', function () {
    $account = app(CreateAccount::class)->execute($this->owner, [
        'name' => 'Nubank',
        'type' => AccountType::Checking,
        'visibility' => AccountVisibility::Private,
        'currency' => 'brl',
        'initial_balance' => 150000,
    ]);

    expect($account->owner_id)->toBe($this->owner->id)
        ->and($account->household_id)->toBe($this->household->id)
        ->and($account->currency)->toBe('BRL')
        ->and($account->initial_balance->getMinorAmount()->toInt())->toBe(150000);
});

it('rejeita moeda desconhecida', function () {
    app(CreateAccount::class)->execute($this->owner, [
        'name' => 'X', 'type' => 'checking', 'visibility' => 'private', 'currency' => 'XYZ',
    ]);
})->throws(ValidationException::class, 'Moeda desconhecida.');

it('só o dono altera a visibilidade da conta', function () {
    $account = Account::factory()->ownedBy($this->owner)->shared()->create();

    $data = ['name' => 'Conjunta', 'type' => 'checking', 'visibility' => 'private', 'currency' => 'BRL', 'initial_balance' => 0];

    expect(fn () => app(UpdateAccount::class)->execute($this->other, $account, $data))
        ->toThrow(ValidationException::class, 'Só o dono da conta pode alterar a visibilidade.');

    app(UpdateAccount::class)->execute($this->owner, $account, $data);

    expect($account->refresh()->visibility)->toBe(AccountVisibility::Private);
});

it('arquiva e reativa a conta', function () {
    $account = Account::factory()->ownedBy($this->owner)->create();

    app(ArchiveAccount::class)->execute($account);
    expect($account->isArchived())->toBeTrue()
        ->and(Account::active()->count())->toBe(0);

    app(ArchiveAccount::class)->execute($account, archive: false);
    expect($account->isArchived())->toBeFalse();
});
