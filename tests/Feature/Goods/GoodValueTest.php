<?php

use App\Domain\Debts\ManageDebts;
use App\Domain\Goods\GoodValue;
use App\Domain\Goods\ManageGoods;
use App\Domain\Household\CreateHousehold;
use App\Domain\NetWorth\NetWorthCalculator;
use App\Domain\NetWorth\NetWorthScope;
use App\Domain\NetWorth\TakeSnapshots;
use App\Models\Account;
use App\Models\Category;
use App\Models\Good;
use App\Models\NetWorthSnapshot;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Carbon::setTestNow('2026-09-30 10:00:00');

    $this->household = app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $this->eduardo = User::where('email', 'eduardo@example.com')->sole();
    $this->maria = User::factory()->inHousehold($this->household)->create();
    $this->joint = Account::factory()->ownedBy($this->eduardo)->shared()->create(['name' => 'Conjunta', 'initial_balance' => 1000000]);
    $this->personal = Account::factory()->ownedBy($this->eduardo)->private()->create(['name' => 'Pessoal', 'initial_balance' => 0]);
    $this->category = Category::where('household_id', $this->household->id)->where('name', 'Moradia')->sole();

    $this->save = fn (array $data, ?User $actor = null, ?Good $good = null): Good => app(ManageGoods::class)->save($actor ?? $this->eduardo, [
        'name' => 'Apartamento', 'type' => 'property', 'visibility' => 'shared',
        'acquisition_date' => '2024-03-10', 'acquisition_value' => 40000000, ...$data,
    ], $good);

    // Apartamento compartilhado: comprado por 400 mil; avaliado em 450 mil (30/06/2025) e 480 mil (31/03/2026)
    $this->apartment = ($this->save)([]);
    app(ManageGoods::class)->value($this->eduardo, $this->apartment, Carbon::parse('2025-06-30'), 45000000);
    app(ManageGoods::class)->value($this->eduardo, $this->apartment, Carbon::parse('2026-03-31'), 48000000);

    // Carro pessoal do Eduardo: comprado por 50 mil, vendido em 01/07/2026 por 38 mil, sem avaliação
    $this->car = ($this->save)(['name' => 'Carro', 'type' => 'vehicle', 'visibility' => 'private', 'acquisition_date' => '2023-01-15',
        'acquisition_value' => 5000000, 'sale_date' => '2026-07-01', 'sale_value' => 3800000]);
});

it('valor do bem numa data: antes da compra, entre avaliações, depois da venda', function () {
    $at = fn (Good $good, string $date): int => GoodValue::at($good, Carbon::parse($date));

    expect($at($this->apartment, '2024-03-09'))->toBe(0)               // antes da compra
        ->and($at($this->apartment, '2024-03-10'))->toBe(40000000)     // sem avaliação: aquisição
        ->and($at($this->apartment, '2025-06-29'))->toBe(40000000)
        ->and($at($this->apartment, '2025-06-30'))->toBe(45000000)     // no dia da avaliação
        ->and($at($this->apartment, '2026-01-15'))->toBe(45000000)     // entre avaliações: a anterior
        ->and($at($this->apartment, '2026-09-30'))->toBe(48000000)
        ->and($at($this->car, '2026-06-30'))->toBe(5000000)
        ->and($at($this->car, '2026-07-01'))->toBe(0)                  // a partir da venda
        ->and($at($this->car, '2026-09-30'))->toBe(0);
});

it('patrimônio inclui bens: lar só os compartilhados, pessoa também os dela', function () {
    $calculator = app(NetWorthCalculator::class);

    $household = $calculator->at(NetWorthScope::household($this->household), Carbon::parse('2026-06-30'));
    $eduardo = $calculator->at(NetWorthScope::person($this->eduardo), Carbon::parse('2026-06-30'));
    $maria = $calculator->at(NetWorthScope::person($this->maria), Carbon::parse('2026-06-30'));

    expect($household->goodItems)->toBe(['Apartamento' => 48000000])
        ->and($household->netWorth())->toBe(1000000 + 48000000)
        ->and($eduardo->goodItems)->toBe(['Apartamento' => 48000000, 'Carro' => 5000000])
        ->and($eduardo->goods())->toBe(53000000)
        ->and($eduardo->netWorth())->toBe(1000000 + 53000000)
        ->and($maria->goodItems)->toBe(['Apartamento' => 48000000])
        ->and($calculator->at(NetWorthScope::person($this->eduardo), today())->goodItems)->toBe(['Apartamento' => 48000000]);
});

it('recalcular a fotografia de um mês passado repete o valor', function () {
    app(TakeSnapshots::class)->forMonth(Carbon::parse('2026-01-01'), $this->household);
    $january = fn (?int $user) => NetWorthSnapshot::withoutGlobalScopes()->where('user_id', $user)->whereDate('month', '2026-01-01')->sole();

    expect($january(null)->goods)->toBe(45000000)
        ->and($january($this->eduardo->id)->goods)->toBe(50000000)
        ->and($january($this->eduardo->id)->net_worth)->toBe(1000000 + 50000000);

    // Avaliação nova (depois de janeiro) não muda janeiro
    app(ManageGoods::class)->value($this->eduardo, $this->apartment, Carbon::parse('2026-09-15'), 50000000);
    app(TakeSnapshots::class)->forMonth(Carbon::parse('2026-01-01'), $this->household);

    expect($january(null)->goods)->toBe(45000000)
        ->and($january($this->eduardo->id)->net_worth)->toBe(51000000)
        ->and(GoodValue::at($this->apartment, today()))->toBe(50000000);
});

it('valor líquido desconta o saldo do financiamento vinculado', function () {
    $debt = app(ManageDebts::class)->create($this->eduardo, [
        'name' => 'Financiamento', 'creditor' => 'Caixa', 'principal' => 30000000, 'monthly_rate' => '0.8', 'system' => 'sac',
        'installments_count' => 360, 'first_due_date' => '2026-10-10', 'payment_account_id' => $this->joint->id, 'category_id' => $this->category->id,
    ]);

    $apartment = ($this->save)(['debt_id' => $debt->id], good: $this->apartment);

    expect(GoodValue::linkedDebt($apartment))->toBe(30000000)
        ->and(GoodValue::net($apartment))->toBe(48000000 - 30000000);
});

it('bem compartilhado não vincula dívida paga por conta pessoal', function () {
    $debt = app(ManageDebts::class)->create($this->eduardo, [
        'name' => 'Empréstimo', 'creditor' => 'Banco', 'principal' => 1000000, 'monthly_rate' => '1', 'system' => 'price',
        'installments_count' => 12, 'first_due_date' => '2026-10-15', 'payment_account_id' => $this->personal->id, 'category_id' => $this->category->id,
    ]);

    expect(fn () => ($this->save)(['debt_id' => $debt->id], good: $this->apartment))->toThrow(ValidationException::class);

    // Pessoal do dono pode
    $car = ($this->save)(['debt_id' => $debt->id, 'visibility' => 'private'], good: $this->car);
    expect($car->debt_id)->toBe($debt->id);
});

it('valida datas e visibilidade', function () {
    expect(fn () => ($this->save)(['sale_date' => '2024-01-01', 'sale_value' => 1]))->toThrow(ValidationException::class)
        ->and(fn () => ($this->save)(['acquisition_date' => '2026-10-10']))->toThrow(ValidationException::class)
        ->and(fn () => app(ManageGoods::class)->value($this->eduardo, $this->apartment, Carbon::parse('2024-01-01'), 1))->toThrow(ValidationException::class)
        ->and(fn () => ($this->save)(['visibility' => 'private'], $this->maria, $this->apartment))->toThrow(ValidationException::class)
        ->and(fn () => app(ManageGoods::class)->value($this->maria, $this->car, today(), 1))->toThrow(ValidationException::class);
});

it('lembrete: sem avaliação há mais de 6 meses', function () {
    expect(GoodValue::isStale($this->apartment))->toBeTrue()      // 31/03/2026: 183 dias
        ->and(GoodValue::isStale($this->car))->toBeFalse();       // vendido

    app(ManageGoods::class)->value($this->eduardo, $this->apartment, Carbon::parse('2026-09-01'), 48500000);

    expect(GoodValue::isStale($this->apartment))->toBeFalse();
});

it('usuário B não vê o bem pessoal de A', function () {
    $this->actingAs($this->maria);

    expect(Good::query()->pluck('name')->all())->toBe(['Apartamento'])
        ->and($this->maria->can('view', $this->car))->toBeFalse()
        ->and($this->maria->can('view', $this->apartment))->toBeTrue();
});
