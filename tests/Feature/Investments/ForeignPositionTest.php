<?php

use App\Domain\Household\CreateHousehold;
use App\Domain\Investments\ManageOperations;
use App\Domain\Investments\Portfolio;
use App\Domain\Investments\PriceBook;
use App\Domain\Investments\ReturnCalculator;
use App\Domain\Investments\SaveAsset;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');

    $household = app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $this->user = User::where('email', 'eduardo@example.com')->sole();
    $this->maria = User::factory()->inHousehold($household)->create();
    $this->usBroker = Account::factory()->ownedBy($this->user)->private()->create(['type' => AccountType::Brokerage, 'currency' => 'USD', 'name' => 'Avenue']);
    $broker = Account::factory()->ownedBy($this->user)->shared()->create(['type' => AccountType::Brokerage, 'name' => 'XP']);

    foreach (['2026-01-10' => '5.00', '2026-03-10' => '5.50', '2026-06-10' => '5.20', '2026-09-19' => '5.40'] as $date => $rate) {
        ExchangeRate::create(['currency' => 'USD', 'date' => $date, 'rate' => $rate]);
    }

    $ops = app(ManageOperations::class);
    $this->aapl = app(SaveAsset::class)->execute($this->user, ['account_id' => $this->usBroker->id, 'type' => 'stock', 'ticker' => 'AAPL', 'name' => 'Apple']);
    // 10 × US$ 100 a R$ 5,00 → US$ 1.000 / R$ 5.000
    $ops->register($this->user, $this->aapl, ['type' => 'buy', 'date' => '2026-01-10', 'quantity' => '10', 'unit_price' => '100']);
    // 10 × US$ 120 a R$ 5,50 → US$ 2.200 / R$ 11.600; PM US$ 110
    $ops->register($this->user, $this->aapl, ['type' => 'buy', 'date' => '2026-03-10', 'quantity' => '10', 'unit_price' => '120']);
    // venda de 5 × US$ 130 a R$ 5,20 → custo US$ 1.650 (15 × 110) e R$ 8.700 (11.600 × 15/20)
    $ops->register($this->user, $this->aapl, ['type' => 'sell', 'date' => '2026-06-10', 'quantity' => '5', 'unit_price' => '130']);
    app(PriceBook::class)->setManual($this->user, $this->aapl, Carbon::parse('2026-09-19'), '150');

    $this->petr = app(SaveAsset::class)->execute($this->user, ['account_id' => $broker->id, 'type' => 'stock', 'ticker' => 'PETR4', 'name' => 'Petrobras']);
    $ops->register($this->user, $this->petr, ['type' => 'buy', 'date' => '2026-01-10', 'quantity' => '100', 'unit_price' => '10']);
    app(PriceBook::class)->setManual($this->user, $this->petr, Carbon::parse('2026-09-19'), '11');
});

it('posição em moeda original e em reais, com variação do ativo e cambial separadas', function () {
    $this->actingAs($this->user);
    $row = collect(app(Portfolio::class)->rows())->first(fn ($r) => $r->asset->is($this->aapl));

    // mercado US$ 2.250 (15 × 150) × 5,40 = R$ 12.150; resultado R$ 12.150 − 8.700 = 3.450
    // ativo: (2.250 − 1.650) × 5,40 = 3.240; câmbio: 1.650 × (5,40 − 5,2727…) = 210
    expect($row->cost()->getMinorAmount()->toInt())->toBe(165000)
        ->and($row->marketValue()->getMinorAmount()->toInt())->toBe(225000)
        ->and($row->costBrl()->getMinorAmount()->toInt())->toBe(870000)
        ->and($row->marketValueBrl()->getMinorAmount()->toInt())->toBe(1215000)
        ->and($row->resultBrl()->getMinorAmount()->toInt())->toBe(345000)
        ->and($row->assetEffectBrl()->getMinorAmount()->toInt())->toBe(324000)
        ->and($row->fxEffectBrl()->getMinorAmount()->toInt())->toBe(21000)
        ->and((string) $row->averageRate())->toBe('5.2727');
});

it('totais da carteira somam B3 e exterior em reais', function () {
    $this->actingAs($this->user);
    $portfolio = app(Portfolio::class);
    $totals = $portfolio->totals($portfolio->rows());

    expect($totals['cost']->getMinorAmount()->toInt())->toBe(870000 + 100000)
        ->and($totals['market']->getMinorAmount()->toInt())->toBe(1215000 + 110000)
        ->and($totals['result']->getMinorAmount()->toInt())->toBe(355000)
        ->and($totals['missing'])->toBe([]);
});

it('sem câmbio o ativo fica fora dos totais e é apontado', function () {
    ExchangeRate::query()->delete();
    $this->actingAs($this->user);
    $portfolio = app(Portfolio::class);

    expect($portfolio->totals($portfolio->rows())['missing'])->toBe(['AAPL']);
});

it('rentabilidade em reais usa o câmbio de cada data', function () {
    $this->actingAs($this->user);
    $row = collect(app(ReturnCalculator::class)->forPeriod(Carbon::parse('2026-06-01'), Carbon::parse('2026-09-19'))['assets'])
        ->first(fn ($r) => $r->asset->is($this->aapl));

    // 111 dias. Início (31/05): 20 × PM 110 (sem cotação) = US$ 2.200 × 5,50 = R$ 12.100
    // Venda em 10/06 (dia 9): US$ 650 × 5,20 = R$ 3.380, peso 102/111. Fim: US$ 2.250 × 5,40 = R$ 12.150
    // resultado = 12.150 − 12.100 + 3.380 = 3.430; base = 12.100 − 3.380 × 102/111 = 8.994,05 → 38,14%
    expect($row->startValue)->toBe(1210000)
        ->and($row->sells)->toBe(338000)
        ->and($row->endValue)->toBe(1215000)
        ->and($row->result())->toBe(343000)
        ->and($row->percent())->toBe(38.14);
});

it('corretora em USD privada não aparece para o outro usuário', function () {
    $this->actingAs($this->maria);

    expect(collect(app(Portfolio::class)->rows())->map(fn ($r) => $r->asset->label())->all())->toBe(['PETR4']);
});
