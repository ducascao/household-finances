<?php

use App\Models\Household;
use App\Models\User;

it('exige login para acessar o painel', function () {
    $this->get('/')->assertRedirect('/login');
});

it('não tem registro público nem recuperação de senha', function () {
    $this->get('/register')->assertNotFound();
    $this->get('/password-reset/request')->assertNotFound();
});

it('obriga o usuário sem 2FA a configurá-lo antes de usar o painel', function () {
    $user = User::factory()->inHousehold(Household::factory()->create())->create();

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect(route('filament.app.auth.multi-factor-authentication.set-up-required'));
});

it('libera o painel para usuário com 2FA configurado', function () {
    $user = User::factory()->withTwoFactor()->inHousehold(Household::factory()->create())->create();

    $this->actingAs($user)->get('/')->assertSuccessful();
});

it('bloqueia usuário que não pertence a nenhum lar', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)->get('/')->assertForbidden();
});
