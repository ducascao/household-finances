<?php

use App\Contracts\GoogleOAuth;
use App\Domain\Google\ConnectGoogleDrive;
use App\Domain\Household\CreateHousehold;
use App\Filament\Pages\GoogleDrive;
use App\Models\GoogleConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Fakes\FakeGoogleOAuth;

beforeEach(function () {
    config(['services.google.client_id' => 'client-id', 'services.google.client_secret' => 'secret']);
    $this->oauth = new FakeGoogleOAuth;
    app()->instance(GoogleOAuth::class, $this->oauth);

    $this->household = app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $this->admin = User::where('email', 'eduardo@example.com')->sole();
    $this->admin->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
    $this->member = User::factory()->withTwoFactor()->inHousehold($this->household)->create();
});

function startConnection(): string
{
    $url = app(ConnectGoogleDrive::class)->start(test()->admin);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return (string) $query['state'];
}

it('conecta pelo callback e guarda o token criptografado', function () {
    $this->actingAs($this->admin);

    Livewire::test(GoogleDrive::class)->callAction('connect')->assertRedirect();
    $state = session(ConnectGoogleDrive::SESSION_KEY)['state'];

    $this->get(route('google.callback', ['state' => $state, 'code' => 'abc']))
        ->assertRedirect(GoogleDrive::getUrl());

    $connection = GoogleConnection::sole();

    expect($connection->email)->toBe('casa@gmail.com')
        ->and($connection->refresh_token)->toBe('refresh-token-fake')
        ->and($connection->root_folder)->toBe('Finanças de Casa')
        ->and(DB::table('google_connections')->value('refresh_token'))->not->toContain('refresh-token-fake');
});

it('recusa callback com state errado (CSRF) ou código inválido', function () {
    $this->actingAs($this->admin);
    startConnection();

    $this->get(route('google.callback', ['state' => 'forjado', 'code' => 'abc']));
    expect(GoogleConnection::count())->toBe(0);

    $state = startConnection();
    $this->get(route('google.callback', ['state' => $state, 'code' => 'invalid']));
    expect(GoogleConnection::count())->toBe(0);

    $state = startConnection();
    $this->get(route('google.callback', ['state' => $state, 'error' => 'access_denied']));
    expect(GoogleConnection::count())->toBe(0);
});

it('só administrador conecta ou vê a página', function () {
    $this->actingAs($this->member);

    expect(fn () => app(ConnectGoogleDrive::class)->start($this->member))->toThrow(ValidationException::class, 'administrador');
    $this->get(GoogleDrive::getUrl())->assertForbidden();
});

it('renomeia a pasta raiz e desconecta revogando o token', function () {
    $this->actingAs($this->admin);
    $state = startConnection();
    app(ConnectGoogleDrive::class)->complete($this->admin, $state, 'abc');

    app(ConnectGoogleDrive::class)->renameRootFolder($this->admin, 'Comprovantes Casa');
    expect(GoogleConnection::sole()->root_folder)->toBe('Comprovantes Casa')
        ->and(fn () => app(ConnectGoogleDrive::class)->renameRootFolder($this->admin, 'a/b'))->toThrow(ValidationException::class);

    Livewire::test(GoogleDrive::class)->assertSee('casa@gmail.com')->callAction('disconnect');

    expect(GoogleConnection::count())->toBe(0)
        ->and($this->oauth->revoked)->toBe(['refresh-token-fake']);
});

it('mostra como configurar quando faltam as credenciais', function () {
    config(['services.google.client_id' => null]);
    $this->actingAs($this->admin);

    Livewire::test(GoogleDrive::class)
        ->assertSee('Credenciais do Google não configuradas')
        ->assertActionHidden('connect');
});
