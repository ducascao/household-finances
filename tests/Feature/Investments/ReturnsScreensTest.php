<?php

use App\Domain\Household\CreateHousehold;
use App\Domain\Investments\ManageOperations;
use App\Domain\Investments\PriceBook;
use App\Domain\Investments\SaveAsset;
use App\Enums\AccountType;
use App\Filament\Pages\ReturnsPage;
use App\Filament\Resources\Assets\Pages\ViewAsset;
use App\Filament\Resources\Assets\RelationManagers\IncomesRelationManager;
use App\Filament\Resources\Assets\RelationManagers\OperationsRelationManager;
use App\Models\Account;
use App\Models\AssetIncome;
use App\Models\InterestRate;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-09-30 20:00:00');

    $household = app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $this->user = User::where('email', 'eduardo@example.com')->sole();
    $this->user->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
    $broker = Account::factory()->ownedBy($this->user)->shared()->create(['type' => AccountType::Brokerage]);

    $this->asset = app(SaveAsset::class)->execute($this->user, ['account_id' => $broker->id, 'type' => 'stock', 'ticker' => 'ABCD3', 'name' => 'Teste']);
    app(ManageOperations::class)->register($this->user, $this->asset, ['type' => 'buy', 'date' => '2026-09-01', 'quantity' => '100', 'unit_price' => '10']);
    app(ManageOperations::class)->register($this->user, $this->asset, ['type' => 'sell', 'date' => '2026-09-15', 'quantity' => '50', 'unit_price' => '12']);
    app(PriceBook::class)->setManual($this->user, $this->asset, Carbon::parse('2026-09-30'), '11');

    foreach (range(1, 21) as $i) {
        InterestRate::create(['series' => 'cdi', 'date' => Carbon::parse('2026-09-01')->addWeekdays($i - 1), 'rate' => '0.05']);
    }

    $this->actingAs($this->user);
});

it('lança provento pela tela do ativo com categoria automática', function () {
    Livewire::test(IncomesRelationManager::class, ['ownerRecord' => $this->asset, 'pageClass' => ViewAsset::class])
        ->callTableAction('create', data: ['type' => 'jcp', 'date' => '2026-09-20', 'gross_amount' => '100,00', 'withheld_tax' => '15,00'])
        ->assertHasNoTableActionErrors()
        ->assertSee('R$ 85,00');

    expect(Transaction::whereNotNull('asset_income_id')->with('category')->sole())
        ->category->name->toBe('JCP')
        ->and(AssetIncome::sole()->netAmount())->toBe(8500);
});

it('mostra o resultado realizado de cada venda', function () {
    // 50 × 12 − 50 × 10 = +100,00
    Livewire::test(OperationsRelationManager::class, ['ownerRecord' => $this->asset, 'pageClass' => ViewAsset::class])
        ->assertSee('Resultado realizado')
        ->assertSee('R$ 100,00');
});

it('página de rentabilidade compara com o CDI e troca o período', function () {
    // Estilos próprios (fc-*) injetados no painel: os cards ficam lado a lado com espaço entre eles.
    $this->get(ReturnsPage::getUrl())->assertSuccessful()->assertSee('.fc-cards { display: grid; gap: 1rem;', false)->assertSee('class="fc-cards"', false);

    // Mês atual: valor inicial 0; compra 1.000 no dia 1 (peso 1) e venda 600 no dia 15 (peso 16/30)
    // resultado = 550 − 0 − 1.000 + 600 = 150; base = 1.000 − 600 × 16/30 = 680 → 22,06%
    Livewire::test(ReturnsPage::class)
        ->callAction('period_month')
        ->assertSet('period', 'month')
        ->assertSee('22,06%')
        ->assertSee('1,06%')      // CDI: 1,0005^21 − 1 = 1,0553%
        ->assertSee('ABCD3')
        ->callAction('customPeriod', ['from' => '2026-09-16', 'to' => '2026-09-30'])
        ->assertSet('period', 'custom')
        ->assertSee('16/09/2026 a 30/09/2026');
});
