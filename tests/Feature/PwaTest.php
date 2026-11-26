<?php

use App\Domain\Household\CreateHousehold;
use App\Models\User;
use Filament\Pages\Dashboard;

it('tem um manifest válido com ícones que existem', function () {
    $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest)
        ->name->toBe('Finanças de Casa')
        ->short_name->toBe('Finanças')
        ->start_url->toBe('/')
        ->scope->toBe('/')
        ->display->toBe('standalone')
        ->lang->toBe('pt-BR');

    $pngs = collect($manifest['icons'])->where('type', 'image/png');

    // O navegador exige ícones PNG de 192 e 512 para instalar; o "maskable" é recortado pelo sistema.
    foreach (['any', 'maskable'] as $purpose) {
        expect($pngs->where('purpose', $purpose)->pluck('sizes')->all())->toContain('192x192', '512x512');
    }

    foreach ($manifest['icons'] as $icon) {
        $file = public_path(ltrim($icon['src'], '/'));
        expect($file)->toBeFile();

        if ($icon['type'] === 'image/png') {
            [$width, $height] = getimagesize($file) ?: [0, 0];
            expect("{$width}x{$height}")->toBe($icon['sizes']);
        }
    }
});

it('o login traz o manifest e registra o service worker', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('<link rel="manifest" href="'.asset('manifest.webmanifest').'">', escape: false)
        ->assertSee('<meta name="theme-color" content="#1f9d55">', escape: false)
        ->assertSee('navigator.serviceWorker.register', escape: false);
});

it('o painel traz o manifest e o botão de instalar o app', function () {
    app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $user = User::where('email', 'eduardo@example.com')->sole();
    $user->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();

    $this->actingAs($user)
        ->get(Dashboard::getUrl())
        ->assertOk()
        ->assertSee('rel="manifest"', escape: false)
        ->assertSee('fc-pwa-install', escape: false)
        ->assertSee('Instalar app');
});

it('o service worker não guarda páginas nem dados em cache', function () {
    $sw = (string) file_get_contents(public_path('sw.js'));

    // Só a página "Sem conexão" e o ícone dela vão para o cache, na instalação.
    expect($sw)
        ->toContain("const OFFLINE_ASSETS = ['/offline.html', '/icons/icon.svg'];")
        ->not->toContain('cache.put')
        ->and(substr_count($sw, 'cache.addAll'))->toBe(1);

    expect(public_path('offline.html'))->toBeFile();
});
