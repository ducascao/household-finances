<?php

use App\Domain\Household\CreateHousehold;
use App\Domain\Investments\ManageOperations;
use App\Domain\Investments\PriceBook;
use App\Domain\Investments\SaveAsset;
use App\Enums\AccountType;
use App\Filament\Pages\PortfolioPage;
use App\Filament\Portfolio\PortfolioTotalsWidget;
use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');

    app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $this->user = User::where('email', 'eduardo@example.com')->sole();
    $this->user->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
    $usBroker = Account::factory()->ownedBy($this->user)->create(['type' => AccountType::Brokerage, 'currency' => 'USD']);

    ExchangeRate::create(['currency' => 'USD', 'date' => '2026-09-01', 'rate' => '5.00']);
    ExchangeRate::create(['currency' => 'USD', 'date' => '2026-09-18', 'rate' => '5.50']);

    $this->voo = app(SaveAsset::class)->execute($this->user, ['account_id' => $usBroker->id, 'type' => 'etf', 'ticker' => 'VOO', 'name' => 'Vanguard S&P 500']);
    app(ManageOperations::class)->register($this->user, $this->voo, ['type' => 'buy', 'date' => '2026-09-01', 'quantity' => '2', 'unit_price' => '500']);
    app(PriceBook::class)->setManual($this->user, $this->voo, Carbon::parse('2026-09-18'), '510');

    $this->actingAs($this->user);
});

it('mostra o exterior na moeda original e em reais na carteira', function () {
    // custo US$ 1.000 × 5,00 = R$ 5.000; valor US$ 1.020 × 5,50 = R$ 5.610; câmbio: 1.000 × 0,50 = R$ 500
    Livewire::test(PortfolioPage::class)
        ->assertSee('VOO')
        ->assertSee('US$ 500,00')
        ->assertSee('R$ 5.000,00')
        ->assertSee('US$ 1.020,00')
        ->assertSee('R$ 5.610,00')
        ->assertSee('câmbio R$ 500,00');

    Livewire::test(PortfolioTotalsWidget::class)->assertSee('R$ 5.610,00');
});

it('tela do ativo mostra câmbio médio, atual e a separação do resultado', function () {
    $this->get(route('filament.app.resources.assets.view', $this->voo))
        ->assertSuccessful()
        ->assertSee('Câmbio médio de compra')
        ->assertSee('R$ 5,00')
        ->assertSee('R$ 5,50')
        ->assertSee('R$ 110,00')   // variação do ativo: US$ 20 × 5,50
        ->assertSee('R$ 500,00');  // variação cambial
});

it('sem câmbio avisa que precisa ser atualizado', function () {
    ExchangeRate::query()->delete();

    Livewire::test(PortfolioTotalsWidget::class)->assertSee('Fora dos totais por falta de câmbio: VOO');
    $this->get(route('filament.app.resources.assets.view', $this->voo))->assertSee('sem câmbio: precisa ser atualizado');
});
