<?php

use App\Domain\Currency\ExchangeRates;
use App\Domain\Currency\MissingExchangeRate;
use App\Jobs\FetchExchangeRates;
use App\Models\Account;
use App\Models\ExchangeRate;
use Brick\Money\Money;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Carbon::setTestNow('2026-09-21 14:00:00');
    config(['services.bcb.ptax_url' => 'https://ptax.test']);
});

function ptaxRow(string $dateTime, float $sell, string $bulletin = 'Fechamento'): array
{
    return ['cotacaoVenda' => $sell, 'dataHoraCotacao' => $dateTime, 'tipoBoletim' => $bulletin];
}

it('conversão usa o câmbio da data ou o último anterior disponível', function () {
    ExchangeRate::create(['currency' => 'USD', 'date' => '2026-09-17', 'rate' => '5.30']);
    ExchangeRate::create(['currency' => 'USD', 'date' => '2026-09-18', 'rate' => '5.40']);
    $rates = app(ExchangeRates::class);
    $usd = Money::of('100.00', 'USD');

    expect($rates->toBrl($usd, Carbon::parse('2026-09-18'))->getMinorAmount()->toInt())->toBe(54000)   // dia com taxa
        ->and($rates->toBrl($usd, Carbon::parse('2026-09-20'))->getMinorAmount()->toInt())->toBe(54000) // domingo → sexta
        ->and($rates->toBrl($usd, Carbon::parse('2026-09-17'))->getMinorAmount()->toInt())->toBe(53000)
        ->and($rates->toBrl(Money::of('10.00', 'BRL'), Carbon::parse('2020-01-01'))->getMinorAmount()->toInt())->toBe(1000)
        ->and(fn () => $rates->toBrl($usd, Carbon::parse('2026-09-16')))->toThrow(MissingExchangeRate::class, 'câmbio precisa ser atualizado')
        ->and($rates->minorToBrl(1, 'USD', Carbon::parse('2026-09-18')))->toBe(5); // 0,01 × 5,40 = 0,054 → 0,05
});

it('busca a PTAX de fechamento das moedas das contas, sem duplicar', function () {
    Account::factory()->create(['currency' => 'USD']);
    Http::fake(['ptax.test/*' => Http::response(['value' => [
        ptaxRow('2026-09-17 13:04:20.227', 5.3012),
        ptaxRow('2026-09-18 10:08:00.000', 5.39, 'Abertura'),
        ptaxRow('2026-09-18 13:05:00.000', 5.4021),
    ]])]);

    (new FetchExchangeRates)->handle(app(ExchangeRates::class));
    (new FetchExchangeRates)->handle(app(ExchangeRates::class));

    expect(ExchangeRate::orderBy('date')->get()->map(fn ($r) => [$r->date->toDateString(), $r->rate])->all())->toBe([
        ['2026-09-17', '5.30120000'],
        ['2026-09-18', '5.40210000'],
    ]);

    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), "@moeda='USD'")
        && str_contains(urldecode($request->url()), "@dataInicial='09-11-2026'"));
});

it('falha da API não quebra o job', function () {
    Account::factory()->create(['currency' => 'USD']);
    Http::fake(['ptax.test/*' => Http::response('erro', 500)]);

    (new FetchExchangeRates)->handle(app(ExchangeRates::class));

    expect(ExchangeRate::count())->toBe(0);
});

it('comando carrega moedas informadas e o job está agendado', function () {
    Http::fake(['ptax.test/*' => Http::response(['value' => [ptaxRow('2026-09-18 13:05:00.000', 6.1)]])]);

    $this->artisan('app:fetch-exchange-rates', ['--from' => '2026-09-01', '--currency' => ['eur']])->assertSuccessful();

    expect(ExchangeRate::sole()->currency)->toBe('EUR')
        ->and(collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->description, FetchExchangeRates::class))?->expression)
        ->toBe('30 13 * * 1-5');
});
