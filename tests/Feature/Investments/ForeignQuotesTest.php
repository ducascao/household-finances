<?php

use App\Domain\Household\CreateHousehold;
use App\Domain\Investments\PriceBook;
use App\Domain\Investments\SaveAsset;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AssetPrice;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Carbon::setTestNow('2026-09-21 19:00:00');
    config([
        'services.brapi.url' => 'https://brapi.test',
        'services.finnhub.url' => 'https://finnhub.test', 'services.finnhub.token' => 'fh-token',
    ]);

    app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $user = User::where('email', 'eduardo@example.com')->sole();
    $broker = Account::factory()->ownedBy($user)->create(['type' => AccountType::Brokerage]);
    $usBroker = Account::factory()->ownedBy($user)->create(['type' => AccountType::Brokerage, 'currency' => 'USD']);

    $this->petr = app(SaveAsset::class)->execute($user, ['account_id' => $broker->id, 'type' => 'stock', 'ticker' => 'PETR4', 'name' => 'Petrobras']);
    $this->aapl = app(SaveAsset::class)->execute($user, ['account_id' => $usBroker->id, 'type' => 'stock', 'ticker' => 'AAPL', 'name' => 'Apple']);
    $this->o = app(SaveAsset::class)->execute($user, ['account_id' => $usBroker->id, 'type' => 'reit', 'ticker' => 'O', 'name' => 'Realty Income']);
});

it('busca B3 na brapi e exterior na Finnhub', function () {
    Http::fake([
        'brapi.test/*' => Http::response(['results' => [['regularMarketPrice' => 38.5, 'regularMarketTime' => '2026-09-21T20:00:00Z']]]),
        'finnhub.test/api/v1/quote?symbol=AAPL*' => Http::response(['c' => 227.52, 't' => 1789500000]),
        'finnhub.test/api/v1/quote?symbol=O*' => Http::response(['c' => 0, 't' => 0]), // desconhecido
    ]);

    $result = app(PriceBook::class)->fetchAll();

    expect(AssetPrice::where('asset_id', $this->aapl->id)->sole()->price)->toBe('227.52000000')
        ->and(AssetPrice::where('asset_id', $this->petr->id)->sole()->price)->toBe('38.50000000')
        ->and($this->aapl->currency)->toBe('USD')
        ->and($result['missing'])->toBe(['O']);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'finnhub.test') && str_contains($request->url(), 'token=fh-token'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'brapi.test') && str_contains($request->url(), 'AAPL'));
});

it('falha da Finnhub não afeta as cotações da B3', function () {
    Http::fake([
        'brapi.test/*' => Http::response(['results' => [['regularMarketPrice' => 38.5, 'regularMarketTime' => '2026-09-21T20:00:00Z']]]),
        'finnhub.test/*' => Http::response(['error' => 'API limit reached'], 429),
    ]);

    app(PriceBook::class)->fetchAll();

    expect(AssetPrice::pluck('asset_id')->all())->toBe([$this->petr->id]);
});
