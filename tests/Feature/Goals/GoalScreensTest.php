<?php

use App\Domain\Goals\SaveGoal;
use App\Domain\Household\CreateHousehold;
use App\Enums\AccountType;
use App\Filament\Resources\Goals\Pages\CreateGoal;
use App\Filament\Resources\Goals\Pages\EditGoal;
use App\Filament\Resources\Goals\Pages\ListGoals;
use App\Filament\Widgets\GoalsWidget;
use App\Models\Account;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-09-30 10:00:00');

    $household = app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $this->user = User::where('email', 'eduardo@example.com')->sole();
    $this->user->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
    $this->maria = User::factory()->withTwoFactor()->inHousehold($household)->create();
    $this->savings = Account::factory()->ownedBy($this->user)->shared()->create(['name' => 'Poupança', 'type' => AccountType::Savings, 'initial_balance' => 600000]);
    $this->personal = Account::factory()->ownedBy($this->user)->private()->create(['name' => 'Pessoal', 'initial_balance' => 50000]);

    $this->actingAs($this->user);
});

it('cria meta pelo formulário, mostra progresso e ritmo na lista e no painel', function () {
    Livewire::test(CreateGoal::class)
        ->fillForm(['name' => 'Reserva', 'target' => '12.000,00', 'deadline' => '2027-03-31', 'visibility' => 'shared', 'account_ids' => [$this->savings->id]])
        ->call('create')
        ->assertHasNoFormErrors();

    $goal = Goal::sole();

    // 6.000 de 12.000 (50%); falta 6.000 em 6 meses → 1.000 por mês
    Livewire::test(ListGoals::class)
        ->assertCanSeeTableRecords([$goal])
        ->assertSee('50,0%')
        ->assertSee('R$ 1.000,00');

    Livewire::test(GoalsWidget::class)->assertSee('Reserva')->assertSee('R$ 1.000,00 até 03/2027');

    $this->get(route('filament.app.resources.goals.view', $goal))->assertSuccessful()->assertSee('Conta Poupança: R$ 6.000,00');
});

it('edita os vínculos mantendo as contas marcadas', function () {
    $goal = app(SaveGoal::class)->execute($this->user, [
        'name' => 'Viagem', 'target' => 500000, 'deadline' => '2027-01-31', 'visibility' => 'private', 'account_ids' => [$this->personal->id],
    ]);

    Livewire::test(EditGoal::class, ['record' => $goal->getRouteKey()])
        ->assertFormSet(['account_ids' => [$this->personal->id]])
        ->fillForm(['account_ids' => [$this->personal->id, $this->savings->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($goal->accounts()->withoutGlobalScopes()->count())->toBe(2);
});

it('meta pessoal não aparece para o outro usuário', function () {
    $private = app(SaveGoal::class)->execute($this->user, [
        'name' => 'Presente', 'target' => 100000, 'deadline' => '2026-12-20', 'visibility' => 'private', 'account_ids' => [$this->personal->id],
    ]);

    $this->actingAs($this->maria);

    Livewire::test(ListGoals::class)->assertCanNotSeeTableRecords([$private]);
    $this->get(route('filament.app.resources.goals.view', $private))->assertNotFound();
    expect(GoalsWidget::canView())->toBeFalse();
});
