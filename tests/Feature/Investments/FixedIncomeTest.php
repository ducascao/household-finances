<?php

use App\Domain\Household\CreateHousehold;
use App\Domain\Investments\ManageOperations;
use App\Domain\Investments\ManageValuations;
use App\Domain\Investments\Portfolio;
use App\Domain\Investments\PriceBook;
use App\Domain\Investments\ReturnCalculator;
use App\Domain\Investments\SaveAsset;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');

    $household = app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $this->user = User::where('email', 'eduardo@example.com')->sole();
    $this->maria = User::factory()->inHousehold($household)->create();
    $this->broker = Account::factory()->ownedBy($this->user)->shared()->create(['type' => AccountType::Brokerage, 'name' => 'XP']);
    $this->checking = Account::factory()->ownedBy($this->user)->private()->create(['type' => AccountType::Checking, 'name' => 'Conta Eduardo']);

    $this->ops = app(ManageOperations::class);
    $this->valuations = app(ManageValuations::class);
    $this->save = fn (array $data) => app(SaveAsset::class)->execute($this->user, $data);

    // Ação: 100 × 10,00, cotação 11,00 → custo 1.000, mercado 1.100
    $this->petr = ($this->save)(['account_id' => $this->broker->id, 'type' => 'stock', 'ticker' => 'PETR4', 'name' => 'Petrobras']);
    $this->ops->register($this->user, $this->petr, ['type' => 'buy', 'date' => '2026-06-01', 'quantity' => '100', 'unit_price' => '10']);
    app(PriceBook::class)->setManual($this->user, $this->petr, Carbon::parse('2026-09-19'), '11');

    // CDB: aporte 5.000 (01/07), saldo 5.150 (31/08), aporte 1.000 (10/09) depois do saldo → valor estimado 6.150
    $this->cdb = ($this->save)(['account_id' => $this->broker->id, 'type' => 'fixed_income', 'name' => 'CDB Banco X', 'issuer' => 'Banco X', 'indexer' => 'cdi', 'rate' => '110% do CDI', 'maturity_date' => '2028-07-01']);
    $this->ops->register($this->user, $this->cdb, ['type' => 'contribution', 'date' => '2026-07-01', 'amount' => 500000]);
    $this->valuations->save($this->user, $this->cdb, Carbon::parse('2026-08-31'), 515000);
    $this->ops->register($this->user, $this->cdb, ['type' => 'contribution', 'date' => '2026-09-10', 'amount' => 100000]);

    // Previdência (conta corrente privada): aportes 2.000 + 2.000, resgate 500, saldo 3.700 em 15/09
    $this->vgbl = ($this->save)(['account_id' => $this->checking->id, 'type' => 'pension', 'name' => 'VGBL Seguradora Y', 'issuer' => 'Seguradora Y']);
    $this->ops->register($this->user, $this->vgbl, ['type' => 'contribution', 'date' => '2026-06-01', 'amount' => 200000]);
    $this->ops->register($this->user, $this->vgbl, ['type' => 'contribution', 'date' => '2026-07-01', 'amount' => 200000]);
    $this->ops->register($this->user, $this->vgbl, ['type' => 'withdrawal', 'date' => '2026-08-01', 'amount' => 50000]);
    $this->valuations->save($this->user, $this->vgbl, Carbon::parse('2026-09-15'), 370000);
});

it('posição total da carteira inclui renda fixa e previdência pelo último saldo', function () {
    $this->actingAs($this->user);
    $portfolio = app(Portfolio::class);
    $rows = $portfolio->rows();
    $totals = $portfolio->totals($rows);

    expect($totals['cost']->getMinorAmount()->toInt())->toBe(100000 + 600000 + 350000)
        ->and($totals['market']->getMinorAmount()->toInt())->toBe(110000 + 615000 + 370000)
        ->and($totals['result']->getMinorAmount()->toInt())->toBe(45000)
        ->and(array_map(fn ($d) => [$d['type']->label(), $d['percent']], $portfolio->distribution($rows)))->toBe([
            ['Renda fixa', 56.2], ['Previdência', 33.8], ['Ação', 10.0],
        ]);
});

it('rendimento = último saldo − (aportes − resgates), com valor estimado após aporte posterior', function () {
    $this->actingAs($this->user);
    $rows = collect(app(Portfolio::class)->rows())->keyBy(fn ($row) => $row->asset->label());

    expect($rows['CDB Banco X']->valuation)
        ->invested->toBe(600000)
        ->value->toBe(615000)
        ->estimated->toBeTrue()
        ->and($rows['CDB Banco X']->valuation->yield())->toBe(15000)
        ->and($rows['VGBL Seguradora Y']->valuation)
        ->invested->toBe(350000)
        ->value->toBe(370000)
        ->estimated->toBeFalse()
        ->and($rows['VGBL Seguradora Y']->result()->getMinorAmount()->toInt())->toBe(20000);
});

it('aporte e resgate geram lançamento na conta do ativo, fora dos relatórios', function () {
    $transactions = Transaction::where('account_id', $this->checking->id)->orderBy('date')->get();

    expect($transactions->map(fn ($t) => [$t->description, $t->amount->getMinorAmount()->toInt(), $t->category_id])->all())->toBe([
        ['Aporte em VGBL Seguradora Y', -200000, null],
        ['Aporte em VGBL Seguradora Y', -200000, null],
        ['Resgate de VGBL Seguradora Y', 50000, null],
    ])->and(Transaction::incomeAndExpense()->count())->toBe(0);
});

it('recusa resgate maior que o valor do ativo na data', function () {
    $this->ops->register($this->user, $this->vgbl, ['type' => 'withdrawal', 'date' => '2026-09-18', 'amount' => 370001]);
})->throws(ValidationException::class, 'maior que o valor do ativo');

it('só aceita operações compatíveis com o tipo do ativo', function () {
    expect(fn () => $this->ops->register($this->user, $this->cdb, ['type' => 'buy', 'date' => '2026-09-18', 'quantity' => '1', 'unit_price' => '1']))
        ->toThrow(ValidationException::class, 'aporte e resgate')
        ->and(fn () => $this->ops->register($this->user, $this->petr, ['type' => 'contribution', 'date' => '2026-09-18', 'amount' => 100]))
        ->toThrow(ValidationException::class, 'compra, venda');
});

it('ticker só é exigido para ativos da B3; renda fixa aceita conta corrente', function () {
    expect($this->cdb->ticker)->toBeNull()
        ->and($this->cdb->indexer?->label())->toBe('CDI')
        ->and(fn () => ($this->save)(['account_id' => $this->broker->id, 'type' => 'stock', 'name' => 'Sem ticker']))
        ->toThrow(ValidationException::class)
        ->and(fn () => ($this->save)(['account_id' => $this->checking->id, 'type' => 'stock', 'ticker' => 'VALE3', 'name' => 'Vale']))
        ->toThrow(ValidationException::class, 'corretora');
});

it('rentabilidade de renda fixa no período confere com o cálculo manual', function () {
    $this->valuations->save($this->user, $this->cdb, Carbon::parse('2026-09-19'), 620000);
    $this->actingAs($this->user);

    $row = collect(app(ReturnCalculator::class)->forPeriod(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-19'))['assets'])
        ->first(fn ($r) => $r->asset->is($this->cdb));

    // 19 dias; início = saldo de 31/08 (5.150); fim = saldo de 19/09 (6.200); aporte 1.000 no dia 9 → peso 10/19
    // resultado = 6.200 − 5.150 − 1.000 = 50; base = 5.150 + 1.000 × 10/19 = 5.676,32 → 0,88%
    expect($row->startValue)->toBe(515000)
        ->and($row->endValue)->toBe(620000)
        ->and($row->buys)->toBe(100000)
        ->and($row->result())->toBe(5000)
        ->and($row->percent())->toBe(0.88);
});

it('previdência em conta privada não aparece para o outro usuário', function () {
    $this->actingAs($this->maria);

    expect(collect(app(Portfolio::class)->rows())->map(fn ($r) => $r->asset->label())->sort()->values()->all())
        ->toBe(['CDB Banco X', 'PETR4']);
});

it('saldo informado: não aceita data futura, valor negativo nem ativo da B3', function () {
    expect(fn () => $this->valuations->save($this->user, $this->cdb, Carbon::parse('2026-09-21'), 1))->toThrow(ValidationException::class, 'futura')
        ->and(fn () => $this->valuations->save($this->user, $this->cdb, Carbon::parse('2026-09-19'), -1))->toThrow(ValidationException::class)
        ->and(fn () => $this->valuations->save($this->user, $this->petr, Carbon::parse('2026-09-19'), 1))->toThrow(ValidationException::class, 'renda fixa');
});
