<?php

use App\Domain\Household\CreateHousehold;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['attachments.disk' => 'google']);
    Storage::fake('google');

    $this->dir = storage_path('framework/testing/backups');
    File::ensureDirectoryExists($this->dir);
    File::cleanDirectory($this->dir);
    config(['backup.path' => $this->dir]);

    app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
});

afterEach(fn () => File::deleteDirectory($this->dir));

it('envia o dump mais recente para Backups/ e não reenvia', function () {
    File::put($this->dir.'/household_finances_2026-09-27_0300.dump', 'antigo');
    touch($this->dir.'/household_finances_2026-09-27_0300.dump', now()->subDay()->getTimestamp());
    File::put($this->dir.'/household_finances_2026-09-28_0300.dump', 'novo');

    $this->artisan('app:backup-to-drive')->expectsOutputToContain('enviado ao Drive')->assertSuccessful();
    $this->artisan('app:backup-to-drive')->expectsOutputToContain('já está no Drive')->assertSuccessful();

    Storage::disk('google')->assertExists('Backups/household_finances_2026-09-28_0300.dump');
    Storage::disk('google')->assertMissing('Backups/household_finances_2026-09-27_0300.dump');
    expect(Storage::disk('google')->get('Backups/household_finances_2026-09-28_0300.dump'))->toBe('novo');
});

it('mantém só 30 dias no Drive', function () {
    Storage::disk('google')->put('Backups/velho.dump', 'x');
    Storage::disk('google')->put('Backups/recente.dump', 'x');
    touch(Storage::disk('google')->path('Backups/velho.dump'), now()->subDays(31)->getTimestamp());
    touch(Storage::disk('google')->path('Backups/recente.dump'), now()->subDays(29)->getTimestamp());
    File::put($this->dir.'/hoje.dump', 'x');

    $this->artisan('app:backup-to-drive')->assertSuccessful();

    Storage::disk('google')->assertMissing('Backups/velho.dump');
    Storage::disk('google')->assertExists('Backups/recente.dump');
});

it('não envia quando há mais de um lar e nenhum foi configurado', function () {
    app(CreateHousehold::class)->execute('Outra casa', 'Ana', 'ana@example.com', 'senha-forte');
    File::put($this->dir.'/hoje.dump', 'x');

    $this->artisan('app:backup-to-drive')->expectsOutputToContain('Nenhum lar definido')->assertSuccessful();

    expect(Storage::disk('google')->allFiles())->toBe([]);
});

it('está agendado para as 04:00', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'app:backup-to-drive'));

    expect($event?->expression)->toBe('0 4 * * *');
});
