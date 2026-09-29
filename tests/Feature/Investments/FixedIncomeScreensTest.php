<?php

use App\Domain\Household\CreateHousehold;
use App\Domain\Investments\ManageOperations;
use App\Domain\Investments\ManageValuations;
use App\Domain\Investments\SaveAsset;
use App\Enums\AccountType;
use App\Filament\Pages\PortfolioPage;
use App\Filament\Resources\Assets\Pages\CreateAsset;
use App\Filament\Resources\Assets\Pages\ViewAsset;
use App\Filament\Resources\Assets\RelationManagers\IncomesRelationManager;
use App\Filament\Resources\Assets\RelationManagers\OperationsRelationManager;
use App\Filament\Resources\Assets\RelationManagers\ValuationsRelationManager;
use App\Filament\Widgets\StaleValuationsWidget;
use App\Models\Account;
use App\Models\Asset;
use App\Models\ManualValuation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');

    $household = app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $this->user = User::where('email', 'eduardo@example.com')->sole();
    $this->user->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
    $this->checking = Account::factory()->ownedBy($this->user)->create(['type' => AccountType::Checking, 'name' => 'Conta']);

    $this->vgbl = app(SaveAsset::class)->execute($this->user, ['account_id' => $this->checking->id, 'type' => 'pension', 'name' => 'VGBL Y']);
    app(ManageOperations::class)->register($this->user, $this->vgbl, ['type' => 'contribution', 'date' => '2026-07-01', 'amount' => 300000]);
    app(ManageValuations::class)->save($this->user, $this->vgbl, Carbon::parse('2026-09-01'), 310000);

    $this->actingAs($this->user);
});

it('cadastra renda fixa sem ticker, com indexador e taxa, numa conta corrente', function () {
    Livewire::test(CreateAsset::class)
        ->fillForm(['type' => 'fixed_income'])
        ->assertFormFieldHidden('ticker')
        ->assertFormFieldVisible('indexer')
        ->fillForm(['name' => 'CDB Banco X', 'account_id' => $this->checking->id, 'issuer' => 'Banco X', 'indexer' => 'cdi', 'rate' => '110% do CDI', 'maturity_date' => '2028-07-01'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Asset::where('name', 'CDB Banco X')->sole())
        ->ticker->toBeNull()
        ->rate->toBe('110% do CDI');
});

it('lança aporte e resgate por valor e esconde as abas da B3', function () {
    Livewire::test(OperationsRelationManager::class, ['ownerRecord' => $this->vgbl, 'pageClass' => ViewAsset::class])
        ->assertSee('Aporte')
        ->callTableAction('create', data: ['type' => 'withdrawal', 'date' => '2026-09-15', 'amount' => '500,00'])
        ->assertHasNoTableActionErrors()
        ->assertSee('R$ 500,00');

    expect(IncomesRelationManager::canViewForRecord($this->vgbl, ViewAsset::class))->toBeFalse()
        ->and(ValuationsRelationManager::canViewForRecord($this->vgbl, ViewAsset::class))->toBeTrue();

    $this->get(route('filament.app.resources.assets.view', $this->vgbl))
        ->assertSuccessful()
        ->assertSee('Investido (aportes − resgates)')
        ->assertSee('R$ 2.500,00')
        ->assertSee('Estimado');
});

it('lembra de atualizar o saldo com mais de 30 dias e some ao informar', function () {
    expect(StaleValuationsWidget::canView())->toBeFalse();

    Carbon::setTestNow('2026-09-20 10:00:00');
    ManualValuation::query()->update(['date' => '2026-08-01']);
    expect(StaleValuationsWidget::canView())->toBeTrue();

    Livewire::test(StaleValuationsWidget::class)
        ->assertSee('VGBL Y')
        ->assertSee('01/08/2026')
        ->callTableAction('informBalance', 'a'.$this->vgbl->id, ['date' => '2026-09-19', 'balance' => '3.150,00'])
        ->assertHasNoTableActionErrors();

    expect(ManualValuation::whereDate('date', '2026-09-19')->sole()->balance)->toBe(315000)
        ->and(StaleValuationsWidget::canView())->toBeFalse();
});

it('informa saldo pela aba Saldos e mostra na carteira', function () {
    Livewire::test(ValuationsRelationManager::class, ['ownerRecord' => $this->vgbl, 'pageClass' => ViewAsset::class])
        ->callTableAction('informBalance', data: ['date' => '2026-09-18', 'balance' => '3.120,00'])
        ->assertHasNoTableActionErrors()
        ->assertSee('R$ 3.120,00');

    Livewire::test(PortfolioPage::class)
        ->assertSee('VGBL Y')
        ->assertSee('saldo informado')
        ->assertSee('18/09/2026')
        ->assertSee('R$ 120,00');
});
