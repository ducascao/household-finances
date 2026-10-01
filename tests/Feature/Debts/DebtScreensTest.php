<?php

use App\Domain\Debts\DebtSummary;
use App\Domain\Debts\ManageDebts;
use App\Domain\Household\CreateHousehold;
use App\Filament\Resources\Debts\Pages\CreateDebt;
use App\Filament\Resources\Debts\Pages\ListDebts;
use App\Filament\Resources\Debts\Pages\ViewDebt;
use App\Filament\Resources\Debts\RelationManagers\InstallmentsRelationManager;
use App\Models\Account;
use App\Models\Category;
use App\Models\Debt;
use App\Models\DebtPrepayment;
use App\Models\InterestRate;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-01-10 10:00:00');

    $household = app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $this->user = User::where('email', 'eduardo@example.com')->sole();
    $this->user->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
    $this->maria = User::factory()->withTwoFactor()->inHousehold($household)->create();
    $this->account = Account::factory()->ownedBy($this->user)->shared()->create(['name' => 'Conjunta']);
    $this->private = Account::factory()->ownedBy($this->user)->private()->create(['name' => 'Pessoal']);
    $this->category = Category::where('household_id', $household->id)->where('name', 'Moradia')->sole();

    $this->actingAs($this->user);
});

it('mostra a prévia e cadastra a dívida pelo formulário', function () {
    Livewire::test(CreateDebt::class)
        ->fillForm([
            'name' => 'Empréstimo', 'creditor' => 'Banco X', 'system' => 'price',
            'principal' => '10.000,00', 'rate' => '1', 'rate_period' => 'monthly', 'installments_count' => 12, 'first_due_date' => '2026-02-15',
        ])
        ->assertSee('R$ 888,49')
        ->fillForm(['payment_account_id' => $this->account->id, 'category_id' => $this->category->id])
        ->call('create')
        ->assertHasNoFormErrors();

    $debt = Debt::sole();

    expect($debt->principal)->toBe(1000000)
        ->and($debt->monthly_rate)->toBe('1.00000000')
        ->and($debt->installments()->count())->toBe(12);
});

it('tela da dívida mostra resumo e cronograma e amortiza', function () {
    $debt = app(ManageDebts::class)->create($this->user, [
        'name' => 'Financiamento', 'creditor' => 'Caixa', 'principal' => 1000000, 'monthly_rate' => '1', 'system' => 'sac',
        'installments_count' => 12, 'first_due_date' => '2026-02-15', 'payment_account_id' => $this->account->id, 'category_id' => $this->category->id,
    ]);

    $this->get(route('filament.app.resources.debts.view', $debt))
        ->assertSuccessful()
        ->assertSee('R$ 10.000,00')
        ->assertSee('0 de 12')
        ->assertSee('15/01/2027');

    Livewire::test(InstallmentsRelationManager::class, ['ownerRecord' => $debt, 'pageClass' => ViewDebt::class])
        ->assertSee('R$ 933,33')
        ->assertSee('Prevista')
        ->assertSee('Futura');

    Livewire::test(ViewDebt::class, ['record' => $debt->getRouteKey()])
        ->callAction('prepay', ['date' => '2026-01-10', 'amount' => '2.000,00', 'mode' => 'reduce_term'])
        ->assertHasNoActionErrors();

    expect(DebtPrepayment::sole()->amount)->toBe(200000);
});

it('lista só as dívidas visíveis', function () {
    $data = fn (Account $account, string $name) => [
        'name' => $name, 'creditor' => 'Banco', 'principal' => 100000, 'monthly_rate' => '1', 'system' => 'price',
        'installments_count' => 3, 'first_due_date' => '2026-02-15', 'payment_account_id' => $account->id, 'category_id' => $this->category->id,
    ];
    $shared = app(ManageDebts::class)->create($this->user, $data($this->account, 'Da casa'));
    $private = app(ManageDebts::class)->create($this->user, $data($this->private, 'Pessoal'));

    $this->actingAs($this->maria);

    Livewire::test(ListDebts::class)->assertCanSeeTableRecords([$shared])->assertCanNotSeeTableRecords([$private]);
    $this->get(route('filament.app.resources.debts.view', $private))->assertNotFound();
});

it('cadastra financiamento com taxa anual, TR e encargos pela tela e ajusta o saldo devedor', function () {
    InterestRate::create(['series' => InterestRate::TR, 'date' => '2026-01-15', 'rate' => '0.1700']);

    Livewire::test(CreateDebt::class)
        ->fillForm([
            'name' => 'Imobiliário', 'creditor' => 'Banco Exemplo', 'system' => 'sac', 'principal' => '100.000,00',
            'rate' => '8,25', 'rate_period' => 'annual_effective', 'installments_count' => 100, 'first_due_date' => '2026-02-15',
            'tr_correction' => true, 'insurance_rate' => '0,0150', 'monthly_fee' => '40,00',
        ])
        // correção 170,00; amortização 100.170 ÷ 100 = 1.001,70; juros 0,66279668% = 663,92; seguro 15,03 + 40,00
        ->assertSee('Juros equivalentes: 0,662797% ao mês')
        ->assertSee('correção do saldo (TR)')
        ->assertSee('R$ 170,00')
        ->assertSee('R$ 1.001,70')
        ->assertSee('R$ 663,92')
        ->assertSee('R$ 55,03')
        ->assertSee('R$ 1.720,65')
        ->fillForm(['payment_account_id' => $this->account->id, 'category_id' => $this->category->id])
        ->call('create')
        ->assertHasNoFormErrors();

    $debt = Debt::sole();
    expect($debt->tr_correction)->toBeTrue()
        ->and($debt->monthly_fee)->toBe(4000)
        ->and($debt->monthly_rate)->toBe('0.66279668');

    $this->get(route('filament.app.resources.debts.view', $debt))->assertSuccessful()->assertSee('corrigido pela TR');

    Livewire::test(InstallmentsRelationManager::class, ['ownerRecord' => $debt, 'pageClass' => ViewDebt::class])
        ->assertSee('Correção (TR)')
        ->assertSee('Seguros/encargos');

    Livewire::test(ViewDebt::class, ['record' => $debt->getRouteKey()])
        ->callAction('adjustBalance', ['balance' => '99.000,00', 'date' => '2026-01-10'])
        ->assertHasNoActionErrors();

    expect((new DebtSummary($debt->refresh()))->outstanding())->toBe(9900000);
});
