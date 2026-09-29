<?php

use App\Domain\Household\CreateHousehold;
use App\Domain\Investments\ManageOperations;
use App\Domain\Investments\Portfolio;
use App\Domain\Investments\PriceBook;
use App\Domain\Investments\SaveAsset;
use App\Enums\AccountType;
use App\Enums\PriceSource;
use App\Jobs\FetchQuotes;
use App\Models\Account;
use App\Models\AssetPrice;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Carbon::setTestNow('2026-09-21 19:00:00');
    config(['services.brapi.url' => 'https://brapi.test', 'services.brapi.token' => 'tok']);

    $household = app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $this->user = User::where('email', 'eduardo@example.com')->sole();
    $broker = Account::factory()->ownedBy($this->user)->shared()->create(['type' => AccountType::Brokerage]);

    $this->petr = app(SaveAsset::class)->execute($this->user, ['account_id' => $broker->id, 'type' => 'stock', 'ticker' => 'PETR4', 'name' => 'Petrobras']);
    $this->hglg = app(SaveAsset::class)->execute($this->user, ['account_id' => $broker->id, 'type' => 'fii', 'ticker' => 'HGLG11', 'name' => 'CSHG Log']);

    foreach ([[$this->petr, '100', '30'], [$this->hglg, '10', '150']] as [$asset, $quantity, $price]) {
        app(ManageOperations::class)->register($this->user, $asset, ['type' => 'buy', 'date' => '2026-09-01', 'quantity' => $quantity, 'unit_price' => $price]);
    }
});

function brapi(string $ticker, float $price, string $time = '2026-09-21T20:07:00.000Z'): array
{
    return ['results' => [['symbol' => $ticker, 'regularMarketPrice' => $price, 'regularMarketTime' => $time]]];
}

it('grava as cotações da brapi com o token', function () {
    Http::fake([
        'brapi.test/api/quote/PETR4*' => Http::response(brapi('PETR4', 38.5)),
        'brapi.test/api/quote/HGLG11*' => Http::response(brapi('HGLG11', 160.12)),
    ]);

    (new FetchQuotes)->handle(app(PriceBook::class));

    expect(AssetPrice::where('asset_id', $this->petr->id)->sole())
        ->price->toBe('38.50000000')
        ->source->toBe(PriceSource::Api)
        ->and(AssetPrice::where('asset_id', $this->petr->id)->sole()->date->toDateString())->toBe('2026-09-21');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'token=tok'));
});

it('falha da API não quebra o job e a posição usa a última cotação disponível', function () {
    Http::fake([
        'brapi.test/api/quote/PETR4*' => Http::sequence()->push(brapi('PETR4', 35, '2026-09-18T20:00:00Z'))->push(['error' => true], 500)->push(['error' => true], 500)->push(['error' => true], 500),
        'brapi.test/api/quote/HGLG11*' => Http::sequence()->push(brapi('HGLG11', 155, '2026-09-18T20:00:00Z'))->pushFailedConnection(),
    ]);

    app(PriceBook::class)->fetchAll();          // sexta: tudo certo
    (new FetchQuotes)->handle(app(PriceBook::class)); // segunda: 500 e timeout, sem exceção

    $this->actingAs($this->user);
    $rows = collect(app(Portfolio::class)->rows())->keyBy(fn ($row) => $row->asset->ticker);

    expect((string) $rows['PETR4']->price())->toBe('35.00000000')
        ->and($rows['PETR4']->lastPrice->date->toDateString())->toBe('2026-09-18')
        ->and($rows['PETR4']->marketValue()->getMinorAmount()->toInt())->toBe(350000)
        ->and((string) $rows['HGLG11']->price())->toBe('155.00000000');
});

it('ticker desconhecido é ignorado e o comando avisa', function () {
    Http::fake([
        'brapi.test/api/quote/PETR4*' => Http::response(brapi('PETR4', 38.5)),
        'brapi.test/api/quote/HGLG11*' => Http::response(['error' => true, 'message' => 'Não encontramos a ação'], 404),
    ]);

    $this->artisan('app:fetch-quotes')
        ->expectsOutputToContain('1 cotação(ões) atualizada(s)')
        ->expectsOutputToContain('HGLG11')
        ->assertSuccessful();
});

it('sem cotação nenhuma, o valor de mercado usa o preço médio', function () {
    $this->actingAs($this->user);
    $row = collect(app(Portfolio::class)->rows())->first(fn ($r) => $r->asset->ticker === 'PETR4');

    expect($row->lastPrice)->toBeNull()
        ->and($row->isPriceStale())->toBeTrue()
        ->and($row->result()->getMinorAmount()->toInt())->toBe(0);
});

it('cotação manual prevalece sobre a automática na mesma data', function () {
    Http::fake(['brapi.test/*' => Http::response(brapi('PETR4', 38.5))]);
    app(PriceBook::class)->fetchAll();
    app(PriceBook::class)->setManual($this->user, $this->petr, Carbon::parse('2026-09-21'), '39,10');

    $latest = app(PriceBook::class)->latestFor([$this->petr->id])->get($this->petr->id);

    expect($latest->price)->toBe('39.10000000')
        ->and($latest->source)->toBe(PriceSource::Manual)
        ->and(fn () => app(PriceBook::class)->setManual($this->user, $this->petr, Carbon::parse('2026-09-22'), '1'))->toThrow(ValidationException::class, 'futura')
        ->and(fn () => app(PriceBook::class)->setManual($this->user, $this->petr, Carbon::parse('2026-09-20'), '0'))->toThrow(ValidationException::class, 'maior que zero');
});

it('totais e distribuição por tipo', function () {
    Http::fake([
        'brapi.test/api/quote/PETR4*' => Http::response(brapi('PETR4', 36)),
        'brapi.test/api/quote/HGLG11*' => Http::response(brapi('HGLG11', 140)),
    ]);
    app(PriceBook::class)->fetchAll();
    $this->actingAs($this->user);

    $portfolio = app(Portfolio::class);
    $rows = $portfolio->rows();
    $totals = $portfolio->totals($rows);

    expect($totals['cost']->getMinorAmount()->toInt())->toBe(300000 + 150000)
        ->and($totals['market']->getMinorAmount()->toInt())->toBe(360000 + 140000)
        ->and($totals['result']->getMinorAmount()->toInt())->toBe(50000)
        ->and($totals['percent'])->toBe(11.11)
        ->and(array_map(fn ($d) => [$d['type']->label(), $d['percent']], $portfolio->distribution($rows)))->toBe([['Ação', 72.0], ['FII', 28.0]]);
});

it('o job está agendado em dias úteis às 19:00', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->description, FetchQuotes::class));

    expect($event?->expression)->toBe('0 19 * * 1-5');
});
