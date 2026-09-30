<?php

use App\Domain\Household\CreateHousehold;
use App\Domain\NetWorth\TakeSnapshots;
use App\Filament\NetWorth\CompositionWidget;
use App\Filament\NetWorth\HistoryTableWidget;
use App\Filament\NetWorth\NetWorthChart;
use App\Filament\NetWorth\NetWorthStatsWidget;
use App\Filament\Pages\NetWorthPage;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-08-31 23:40:00');

    $household = app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $this->user = User::where('email', 'eduardo@example.com')->sole();
    $this->user->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
    $this->joint = Account::factory()->ownedBy($this->user)->shared()->create(['name' => 'Conjunta', 'initial_balance' => 500000]);
    Account::factory()->ownedBy($this->user)->private()->create(['name' => 'Pessoal', 'initial_balance' => 100000]);

    app(TakeSnapshots::class)->forMonth(today());
    Carbon::setTestNow('2026-09-15 10:00:00');
    $this->joint->update(['initial_balance' => 600000]);

    $this->actingAs($this->user);
});

it('abre a página e mostra patrimônio, variação no mês e histórico', function () {
    $this->get(NetWorthPage::getUrl())->assertSuccessful()->assertSee('Patrimônio');

    Livewire::test(NetWorthStatsWidget::class, ['pageFilters' => ['view' => 'me']])
        ->assertSee('R$ 7.000,00')   // 6.000 + 1.000
        ->assertSee('R$ 1.000,00');  // variação desde agosto (6.000 → antes 5.000)

    Livewire::test(HistoryTableWidget::class, ['pageFilters' => ['view' => 'me']])
        ->assertSee('Agosto/2026')
        ->assertSee('R$ 6.000,00')
        ->assertSee('Setembro/2026 (hoje)');

    Livewire::test(NetWorthChart::class, ['pageFilters' => ['view' => 'me']])->assertSuccessful();
});

it('visão do lar mostra só o compartilhado', function () {
    Livewire::test(NetWorthStatsWidget::class, ['pageFilters' => ['view' => 'household']])
        ->assertSee('R$ 6.000,00')
        ->assertSee('só contas, investimentos e dívidas em contas compartilhadas');

    Livewire::test(CompositionWidget::class, ['pageFilters' => ['view' => 'household']])
        ->assertSee('Conjunta')
        ->assertDontSee('Pessoal');

    Livewire::test(CompositionWidget::class, ['pageFilters' => ['view' => 'me']])->assertSee('Pessoal');
});
