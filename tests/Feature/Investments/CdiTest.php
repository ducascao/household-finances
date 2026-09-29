<?php

use App\Domain\Investments\Cdi;
use App\Jobs\FetchCdi;
use App\Models\InterestRate;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Carbon::setTestNow('2026-09-21 09:00:00');
    config(['services.bcb.url' => 'https://bcb.test']);
});

it('grava a série do CDI sem duplicar ao repetir', function () {
    Http::fake(['bcb.test/*' => Http::response([
        ['data' => '17/09/2026', 'valor' => '0.055131'],
        ['data' => '18/09/2026', 'valor' => '0.055131'],
    ])]);

    (new FetchCdi)->handle(app(Cdi::class));
    (new FetchCdi)->handle(app(Cdi::class));

    expect(InterestRate::count())->toBe(2)
        ->and(InterestRate::orderBy('date')->first()->rate)->toBe('0.05513100');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'bcdata.sgs.12')
        && str_contains(urldecode($request->url()), 'dataInicial=22/08/2026'));
});

it('acumula o CDI do período: (1,001 × 1,002 × 1,0005) − 1', function () {
    foreach (['2026-09-01' => '0.1', '2026-09-02' => '0.2', '2026-09-03' => '0.05', '2026-09-04' => '9'] as $date => $rate) {
        InterestRate::create(['series' => 'cdi', 'date' => $date, 'rate' => $rate]);
    }

    // 1,001 × 1,002 = 1,003002; × 1,0005 = 1,0035035010 → 0,3504%
    expect(app(Cdi::class)->accumulated(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-03')))->toBe(0.3504)
        ->and(app(Cdi::class)->accumulated(Carbon::parse('2025-01-01'), Carbon::parse('2025-01-31')))->toBeNull();
});

it('falha da API não quebra o job', function () {
    Http::fake(['bcb.test/*' => Http::response('erro', 503)]);

    (new FetchCdi)->handle(app(Cdi::class));

    expect(InterestRate::count())->toBe(0);
});

it('carrega histórico longo em blocos de até 10 anos', function () {
    Http::fake(['bcb.test/*' => Http::response([])]);

    $this->artisan('app:fetch-cdi', ['--from' => '2010-01-01'])->assertSuccessful();

    Http::assertSentCount(2);
});

it('está agendado diariamente às 09:00', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->description, FetchCdi::class));

    expect($event?->expression)->toBe('0 9 * * *');
});
