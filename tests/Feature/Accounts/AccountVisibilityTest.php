<?php

use App\Models\Account;
use App\Models\Household;
use App\Models\User;

beforeEach(function () {
    $this->household = Household::factory()->create();
    $this->userA = User::factory()->inHousehold($this->household)->create();
    $this->userB = User::factory()->inHousehold($this->household)->create();

    $this->privateOfA = Account::factory()->ownedBy($this->userA)->private()->create();
    $this->privateOfB = Account::factory()->ownedBy($this->userB)->private()->create();
    $this->shared = Account::factory()->ownedBy($this->userB)->shared()->create();
});

it('usuário A não vê as contas privadas do usuário B', function () {
    $this->actingAs($this->userA);

    expect(Account::pluck('id')->all())
        ->toEqualCanonicalizing([$this->privateOfA->id, $this->shared->id])
        ->and(Account::find($this->privateOfB->id))->toBeNull()
        ->and($this->userA->can('view', $this->privateOfB))->toBeFalse()
        ->and($this->userA->can('update', $this->privateOfB))->toBeFalse();
});

it('ambos veem e editam a conta compartilhada', function (string $who) {
    $user = $this->{$who};
    $this->actingAs($user);

    expect(Account::find($this->shared->id))->not->toBeNull()
        ->and($user->can('view', $this->shared))->toBeTrue()
        ->and($user->can('update', $this->shared))->toBeTrue();
})->with(['userA', 'userB']);

it('só o dono muda a visibilidade ou exclui a conta', function () {
    expect($this->userA->can('changeVisibility', $this->shared))->toBeFalse()
        ->and($this->userA->can('delete', $this->shared))->toBeFalse()
        ->and($this->userB->can('changeVisibility', $this->shared))->toBeTrue()
        ->and($this->userB->can('delete', $this->shared))->toBeTrue();
});

it('não mostra contas de outro lar, nem as compartilhadas', function () {
    $stranger = User::factory()->inHousehold(Household::factory()->create())->create();
    $foreignShared = Account::factory()->ownedBy($stranger)->shared()->create();

    $this->actingAs($this->userA);

    expect(Account::find($foreignShared->id))->toBeNull()
        ->and($this->userA->can('view', $foreignShared))->toBeFalse();
});

it('preenche o lar automaticamente ao criar com usuário logado', function () {
    $this->actingAs($this->userA);

    $account = Account::factory()->make(['household_id' => null, 'owner_id' => $this->userA->id]);
    $account->save();

    expect($account->household_id)->toBe($this->household->id);
});
