<?php

use App\Domain\Household\CreateHousehold;
use App\Domain\Investments\ManageIncomes;
use App\Domain\Investments\ManageOperations;
use App\Domain\Investments\PriceBook;
use App\Domain\Investments\ReturnCalculator;
use App\Domain\Investments\SaveAsset;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-10-05 10:00:00');

    $household = app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $this->user = User::where('email', 'eduardo@example.com')->sole();
    $this->maria = User::factory()->inHousehold($household)->create();
    $this->broker = Account::factory()->ownedBy($this->user)->shared()->create(['type' => AccountType::Brokerage]);
    $this->privateBroker = Account::factory()->ownedBy($this->user)->private()->create(['type' => AccountType::Brokerage]);

    $this->asset = app(SaveAsset::class)->execute($this->user, ['account_id' => $this->broker->id, 'type' => 'stock', 'ticker' => 'ABCD3', 'name' => 'Teste']);
    $ops = app(ManageOperations::class);
    $prices = app(PriceBook::class);

    // Antes do período: 100 × 10,00; cotação em 30/06 = 12,00 → valor inicial 1.200,00
    $ops->register($this->user, $this->asset, ['type' => 'buy', 'date' => '2026-06-01', 'quantity' => '100', 'unit_price' => '10']);
    $prices->setManual($this->user, $this->asset, Carbon::parse('2026-06-30'), '12');
    // No período (01/07 a 30/09, 92 dias): compra de 50 × 14,00 em 01/08 (dia 31) e venda de 30 × 15,00 em 01/09 (dia 62)
    $ops->register($this->user, $this->asset, ['type' => 'buy', 'date' => '2026-08-01', 'quantity' => '50', 'unit_price' => '14']);
    $ops->register($this->user, $this->asset, ['type' => 'sell', 'date' => '2026-09-01', 'quantity' => '30', 'unit_price' => '15']);
    // Dividendo de 80,00 em 15/08 e JCP de 100,00 bruto com 15,00 de IR (líquido 85,00) em 20/09
    app(ManageIncomes::class)->register($this->user, $this->asset, ['type' => 'dividend', 'date' => '2026-08-15', 'gross_amount' => 8000]);
    app(ManageIncomes::class)->register($this->user, $this->asset, ['type' => 'jcp', 'date' => '2026-09-20', 'gross_amount' => 10000, 'withheld_tax' => 1500]);
    // Cotação no fim: 16,00 → 120 ações = 1.920,00
    $prices->setManual($this->user, $this->asset, Carbon::parse('2026-09-30'), '16');
});

it('rentabilidade confere com o cálculo manual (Dietz modificado)', function () {
    $this->actingAs($this->user);
    $result = app(ReturnCalculator::class)->forPeriod(Carbon::parse('2026-07-01'), Carbon::parse('2026-09-30'));
    $row = $result['assets'][0];

    // Fluxos: +700,00 com peso 61/92 e −450,00 com peso 30/92
    // Base = 1.200 + 700 × 61/92 − 450 × 30/92 = 1.200 + 464,1304 − 146,7391 = 1.517,3913
    // Resultado = 1.920 − 1.200 − 700 + 450 + 80 + 85 = 635,00 → 635 ÷ 1.517,3913 = 41,85%
    expect($row->startValue)->toBe(120000)
        ->and($row->buys)->toBe(70000)
        ->and($row->sells)->toBe(45000)
        ->and($row->incomes)->toBe(16500)
        ->and($row->endValue)->toBe(192000)
        ->and($row->appreciation())->toBe(47000)
        ->and($row->result())->toBe(63500)
        ->and(round($row->weightedFlows / 100, 4))->toBe(round(700 * 61 / 92 - 450 * 30 / 92, 4))
        ->and($row->percent())->toBe(41.85)
        // Venda de 30 com PM (1.000 + 700) ÷ 150 = 11,3333 → 450 − 340 = +110,00
        ->and($row->realized)->toBe(11000)
        ->and($result['total']->result())->toBe(63500);
});

it('sem cotação usa o preço médio e soma a carteira', function () {
    $other = app(SaveAsset::class)->execute($this->user, ['account_id' => $this->broker->id, 'type' => 'fii', 'ticker' => 'EFGH11', 'name' => 'FII']);
    app(ManageOperations::class)->register($this->user, $other, ['type' => 'buy', 'date' => '2026-07-01', 'quantity' => '10', 'unit_price' => '100']);
    $this->actingAs($this->user);

    $result = app(ReturnCalculator::class)->forPeriod(Carbon::parse('2026-07-01'), Carbon::parse('2026-09-30'));
    $fii = collect($result['assets'])->first(fn ($r) => $r->asset->ticker === 'EFGH11');

    // Sem cotação: vale o PM (100,00) → valorização zero; compra no 1º dia pesa 100%
    expect($fii->endValue)->toBe(100000)
        ->and($fii->result())->toBe(0)
        ->and($fii->percent())->toBe(0.0)
        ->and($result['total']->buys)->toBe(70000 + 100000)
        ->and($result['total']->result())->toBe(63500);
});

it('não mostra ativos de corretora privada a quem não a vê', function () {
    $private = app(SaveAsset::class)->execute($this->user, ['account_id' => $this->privateBroker->id, 'type' => 'stock', 'ticker' => 'PRIV3', 'name' => 'Privado']);
    app(ManageOperations::class)->register($this->user, $private, ['type' => 'buy', 'date' => '2026-07-10', 'quantity' => '1', 'unit_price' => '50']);

    $this->actingAs($this->maria);
    $result = app(ReturnCalculator::class)->forPeriod(Carbon::parse('2026-07-01'), Carbon::parse('2026-09-30'));

    expect(collect($result['assets'])->map(fn ($r) => $r->asset->ticker)->all())->toBe(['ABCD3']);
});
