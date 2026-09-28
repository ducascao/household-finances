<?php

use App\Domain\Household\AddMember;
use App\Domain\Household\CreateHousehold;
use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

it('cria o lar com o primeiro usuário como administrador', function () {
    $household = app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');

    $user = User::where('email', 'eduardo@example.com')->sole();

    expect($user->current_household_id)->toBe($household->id)
        ->and($user->roleIn($household))->toBe(HouseholdRole::Admin)
        ->and(Hash::check('senha-forte', $user->password))->toBeTrue();
});

it('adiciona membro ao lar', function () {
    $household = Household::factory()->create();

    $user = app(AddMember::class)->execute($household, 'Maria', 'maria@example.com', 'senha-forte');

    expect($user->current_household_id)->toBe($household->id)
        ->and($user->roleIn($household))->toBe(HouseholdRole::Member)
        ->and($user->isAdminOf($household))->toBeFalse();
});

it('não aceita e-mail repetido', function () {
    $household = Household::factory()->create();
    User::factory()->create(['email' => 'maria@example.com']);

    app(AddMember::class)->execute($household, 'Maria', 'maria@example.com', 'senha-forte');
})->throws(ValidationException::class, 'já se encontra registrado');

it('cria lar pelo comando artisan', function () {
    $this->artisan('app:create-household')
        ->expectsQuestion('Nome do lar', 'Casa')
        ->expectsQuestion('Nome do administrador', 'Eduardo')
        ->expectsQuestion('E-mail do administrador', 'eduardo@example.com')
        ->expectsQuestion('Senha (mínimo 8 caracteres)', 'senha-forte')
        ->assertSuccessful();

    $household = Household::where('name', 'Casa')->sole();

    expect(User::where('email', 'eduardo@example.com')->sole()->isAdminOf($household))->toBeTrue();
});

it('falha o comando com dados inválidos', function () {
    $this->artisan('app:create-household')
        ->expectsQuestion('Nome do lar', 'Casa')
        ->expectsQuestion('Nome do administrador', 'Eduardo')
        ->expectsQuestion('E-mail do administrador', 'nao-e-email')
        ->expectsQuestion('Senha (mínimo 8 caracteres)', 'curta')
        ->assertFailed();

    expect(Household::count())->toBe(0);
});

it('adiciona membro pelo comando artisan', function () {
    $household = Household::factory()->create();

    $this->artisan('app:add-member')
        ->expectsQuestion('Nome', 'Maria')
        ->expectsQuestion('E-mail', 'maria@example.com')
        ->expectsQuestion('Senha (mínimo 8 caracteres)', 'senha-forte')
        ->expectsQuestion('Papel', HouseholdRole::Member->value)
        ->assertSuccessful();

    expect(User::where('email', 'maria@example.com')->sole()->roleIn($household))->toBe(HouseholdRole::Member);
});
