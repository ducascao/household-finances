<?php

use App\Domain\Household\CreateHousehold;
use App\Filament\Monthly\MonthTotalsWidget;
use App\Filament\Widgets\BillsOverviewWidget;
use App\Models\User;
use Filament\Pages\Dashboard;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 10:00:00');

    app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $this->user = User::where('email', 'eduardo@example.com')->sole();
    $this->user->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
    $this->actingAs($this->user);
});

it('carrega o tema e o menu lateral que recolhe para só os ícones', function () {
    $this->get(Dashboard::getUrl())
        ->assertOk()
        ->assertSee('fi-body-has-sidebar-collapsible-on-desktop', escape: false)
        ->assertSee('fi-topbar-close-collapse-sidebar-btn', escape: false)
        ->assertSee('--fc-card-shadow', escape: false)
        ->assertSee('.fc-tone-success', escape: false);
});

it('cards de resumo têm ícone e a cor do que mostram', function () {
    $bills = Livewire::test(BillsOverviewWidget::class)->html();
    $month = Livewire::test(MonthTotalsWidget::class, ['pageFilters' => ['month' => '2026-11']])->html();

    // Sem lançamentos: nada atrasado nem vencendo (cinza); previsto do mês em azul.
    expect(substr_count($bills, 'fi-wi-stats-overview-stat-label-ctn'))->toBe(3)
        ->and(substr_count($bills, 'fc-tone-gray'))->toBe(2)
        ->and($bills)->toContain('fc-tone-info')
        // Entradas em verde e saídas em vermelho, com o valor na mesma cor.
        ->and($month)->toContain('fc-tone-success fc-tone-value')
        ->and($month)->toContain('fc-tone-danger fc-tone-value')
        ->and(substr_count($month, '<svg'))->toBeGreaterThanOrEqual(3);
});
