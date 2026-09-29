<?php

use App\Domain\Household\CreateHousehold;
use App\Domain\Investments\ManageOperations;
use App\Domain\Investments\PriceBook;
use App\Domain\Investments\SaveAsset;
use App\Enums\AccountType;
use App\Filament\Pages\PortfolioPage;
use App\Filament\Portfolio\PortfolioDistributionWidget;
use App\Filament\Portfolio\PortfolioTotalsWidget;
use App\Filament\Resources\Assets\Pages\CreateAsset;
use App\Filament\Resources\Assets\Pages\ViewAsset;
use App\Filament\Resources\Assets\RelationManagers\OperationsRelationManager;
use App\Filament\Resources\Assets\RelationManagers\PricesRelationManager;
use App\Models\Account;
use App\Models\Asset;
use App\Models\AssetOperation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-09-21 20:00:00');

    $household = app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $this->user = User::where('email', 'eduardo@example.com')->sole();
    $this->user->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
    $this->maria = User::factory()->withTwoFactor()->inHousehold($household)->create();
    $this->broker = Account::factory()->ownedBy($this->user)->shared()->create(['type' => AccountType::Brokerage, 'name' => 'XP']);
    $this->privateBroker = Account::factory()->ownedBy($this->user)->private()->create(['type' => AccountType::Brokerage, 'name' => 'Rico']);

    $this->petr = app(SaveAsset::class)->execute($this->user, ['account_id' => $this->broker->id, 'type' => 'stock', 'ticker' => 'PETR4', 'name' => 'Petrobras']);
    app(ManageOperations::class)->register($this->user, $this->petr, ['type' => 'buy', 'date' => '2026-09-01', 'quantity' => '100', 'unit_price' => '30']);
    app(PriceBook::class)->setManual($this->user, $this->petr, Carbon::parse('2026-09-19'), '33');

    $this->actingAs($this->user);
});

it('mostra totais, distribuição e posições na carteira', function () {
    $this->get(PortfolioPage::getUrl())->assertSuccessful();

    Livewire::test(PortfolioTotalsWidget::class)->assertSee('R$ 3.000,00')->assertSee('R$ 3.300,00')->assertSee('10,00% sobre o custo');
    Livewire::test(PortfolioDistributionWidget::class)->assertSee('Ação')->assertSee('100,0%');
    Livewire::test(PortfolioPage::class)
        ->assertSee('PETR4')
        ->assertSee('R$ 30,00')
        ->assertSee('19/09/2026')
        ->assertSee('R$ 300,00');
});

it('cadastra ativo e lança, edita e exclui operação pela tela do ativo', function () {
    Livewire::test(CreateAsset::class)
        ->fillForm(['ticker' => 'hglg11', 'name' => 'CSHG Logística', 'type' => 'fii', 'account_id' => $this->broker->id])
        ->call('create')
        ->assertHasNoFormErrors();

    $asset = Asset::where('ticker', 'HGLG11')->sole();

    Livewire::test(OperationsRelationManager::class, ['ownerRecord' => $asset, 'pageClass' => ViewAsset::class])
        ->callTableAction('create', data: ['type' => 'buy', 'date' => '2026-09-10', 'quantity' => '10', 'unit_price' => '160,50', 'fees' => '1,20'])
        ->assertHasNoTableActionErrors();

    $operation = AssetOperation::where('asset_id', $asset->id)->sole();
    expect($operation->unit_price)->toBe('160.50000000')->and($operation->fees)->toBe(120);

    Livewire::test(OperationsRelationManager::class, ['ownerRecord' => $asset, 'pageClass' => ViewAsset::class])
        ->callTableAction('create', data: ['type' => 'sell', 'date' => '2026-09-12', 'quantity' => '11', 'unit_price' => '170'])
        ->assertNotified('Operação não registrada');

    Livewire::test(OperationsRelationManager::class, ['ownerRecord' => $asset, 'pageClass' => ViewAsset::class])
        ->callTableAction('edit', $operation, ['quantity' => '12'])
        ->assertHasNoTableActionErrors();

    expect($operation->refresh()->quantity)->toBe('12.00000000');

    Livewire::test(OperationsRelationManager::class, ['ownerRecord' => $asset, 'pageClass' => ViewAsset::class])
        ->callTableAction('delete', $operation);

    expect(AssetOperation::where('asset_id', $asset->id)->count())->toBe(0);
});

it('ajusta a cotação pela tela do ativo', function () {
    Livewire::test(PricesRelationManager::class, ['ownerRecord' => $this->petr, 'pageClass' => ViewAsset::class])
        ->callTableAction('manualPrice', data: ['date' => '2026-09-21', 'price' => '34,20'])
        ->assertHasNoTableActionErrors();

    $this->get(route('filament.app.resources.assets.view', $this->petr))->assertSuccessful()->assertSee('R$ 34,20 em 21/09/2026');
});

it('usuário A não vê ativos da corretora privada de B', function () {
    $private = app(SaveAsset::class)->execute($this->user, ['account_id' => $this->privateBroker->id, 'type' => 'stock', 'ticker' => 'VALE3', 'name' => 'Vale']);
    app(ManageOperations::class)->register($this->user, $private, ['type' => 'buy', 'date' => '2026-09-01', 'quantity' => '10', 'unit_price' => '60']);

    $this->actingAs($this->maria);

    Livewire::test(PortfolioPage::class)->assertSee('PETR4')->assertDontSee('VALE3');
    $this->get(route('filament.app.resources.assets.view', $private))->assertNotFound();
});
