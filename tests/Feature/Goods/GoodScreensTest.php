<?php

use App\Domain\Goods\ManageGoods;
use App\Domain\Household\CreateHousehold;
use App\Filament\NetWorth\CompositionWidget;
use App\Filament\NetWorth\HistoryTableWidget;
use App\Filament\Resources\Goods\GoodResource;
use App\Filament\Resources\Goods\Pages\CreateGood;
use App\Filament\Resources\Goods\Pages\ListGoods;
use App\Filament\Resources\Goods\Pages\ViewGood;
use App\Filament\Widgets\StaleGoodsWidget;
use App\Models\Good;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-09-30 10:00:00');

    $household = app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $this->user = User::where('email', 'eduardo@example.com')->sole();
    $this->user->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
    $this->maria = User::factory()->withTwoFactor()->inHousehold($household)->create();

    $this->actingAs($this->user);
});

it('cadastra bem pelo formulário, informa valor e mostra na lista', function () {
    Livewire::test(CreateGood::class)
        ->fillForm(['name' => 'Carro', 'type' => 'vehicle', 'visibility' => 'shared', 'acquisition_date' => '2026-01-10', 'acquisition_value' => '80.000,00'])
        ->call('create')
        ->assertHasNoFormErrors();

    $good = Good::sole();
    expect($good->acquisition_value)->toBe(8000000);

    Livewire::test(ViewGood::class, ['record' => $good->getRouteKey()])
        ->callAction('informValue', ['date' => '2026-09-01', 'value' => '72.500,00'])
        ->assertHasNoActionErrors();

    Livewire::test(ListGoods::class)
        ->assertCanSeeTableRecords([$good])
        ->assertSee('R$ 72.500,00');

    $this->get(GoodResource::getUrl('view', ['record' => $good]))->assertSuccessful()->assertSee('01/09/2026');
});

it('lembra de atualizar bem sem avaliação há mais de 6 meses', function () {
    app(ManageGoods::class)->save($this->user, [
        'name' => 'Apartamento', 'type' => 'property', 'visibility' => 'shared', 'acquisition_date' => '2025-01-10', 'acquisition_value' => 40000000,
    ]);

    expect(StaleGoodsWidget::canView())->toBeTrue();
    Livewire::test(StaleGoodsWidget::class)->assertSee('Apartamento')->assertSee('aquisição em 10/01/2025');
});

it('bem pessoal não aparece para o outro usuário', function () {
    $private = app(ManageGoods::class)->save($this->user, [
        'name' => 'Moto', 'type' => 'vehicle', 'visibility' => 'private', 'acquisition_date' => '2025-01-10', 'acquisition_value' => 1500000,
    ]);

    $this->actingAs($this->maria);

    Livewire::test(ListGoods::class)->assertCanNotSeeTableRecords([$private]);
    $this->get(GoodResource::getUrl('view', ['record' => $private]))->assertNotFound();
    expect(StaleGoodsWidget::canView())->toBeFalse();
});

it('patrimônio mostra os bens nas duas visões', function () {
    app(ManageGoods::class)->save($this->user, [
        'name' => 'Apartamento', 'type' => 'property', 'visibility' => 'shared', 'acquisition_date' => '2025-01-10', 'acquisition_value' => 40000000,
    ]);
    app(ManageGoods::class)->save($this->user, [
        'name' => 'Moto', 'type' => 'vehicle', 'visibility' => 'private', 'acquisition_date' => '2025-01-10', 'acquisition_value' => 1500000,
    ]);

    Livewire::test(CompositionWidget::class, ['pageFilters' => ['view' => 'household']])
        ->assertSee('Bens')->assertSee('Apartamento')->assertDontSee('Moto');
    Livewire::test(CompositionWidget::class, ['pageFilters' => ['view' => 'me']])->assertSee('Moto');
    Livewire::test(HistoryTableWidget::class, ['pageFilters' => ['view' => 'me']])->assertSee('R$ 415.000,00');
});
