<?php

use App\Domain\Budgets\SaveBudget;
use App\Domain\Household\CreateHousehold;
use App\Domain\Transactions\CreateTransaction;
use App\Filament\Pages\Budgets;
use App\Filament\Widgets\BudgetAlertsWidget;
use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');

    $this->household = app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $this->user = User::where('email', 'eduardo@example.com')->sole();
    $this->user->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
    $this->account = Account::factory()->ownedBy($this->user)->create();
    $this->mercado = Category::where('household_id', $this->household->id)->where('name', 'Mercado')->sole();
    $this->luz = Category::where('household_id', $this->household->id)->where('name', 'Luz')->sole();

    app(SaveBudget::class)->execute($this->household->id, $this->mercado->id, Carbon::parse('2026-09-01'), 50000);
    app(CreateTransaction::class)->execute($this->user, [
        'account_id' => $this->account->id, 'category_id' => $this->mercado->id,
        'amount' => 60000, 'date' => '2026-09-10', 'description' => 'Mercado',
    ]);

    $this->actingAs($this->user);
});

it('mostra orçado × realizado com a situação', function () {
    $this->get(Budgets::getUrl())->assertSuccessful();

    Livewire::test(Budgets::class)
        ->assertSee('Setembro/2026')
        ->assertSee('↳ Mercado')
        ->assertSee('R$ 600,00')
        ->assertSee('-R$ 100,00')
        ->assertSee('120,0%')
        ->assertSee('Estourado');
});

it('edita o orçado na linha e remove com valor vazio', function () {
    $component = Livewire::test(Budgets::class)
        ->call('updateTableColumnState', 'budget', 'c'.$this->mercado->id, '800,00');

    expect(Budget::sole()->amount->getMinorAmount()->toInt())->toBe(80000);

    $component->call('updateTableColumnState', 'budget', 'c'.$this->mercado->id, '');

    expect(Budget::count())->toBe(0);
});

it('define orçamento para outra categoria e navega entre meses', function () {
    Livewire::test(Budgets::class)
        ->callAction('defineBudget', ['category_id' => $this->luz->id, 'amount' => '250,00'])
        ->assertHasNoActionErrors()
        ->assertSee('↳ Luz')
        ->callAction('nextMonth')
        ->assertSet('month', '2026-10')
        ->callAction('copyPreviousMonth')
        ->assertSee('↳ Luz');

    expect(Budget::whereDate('month', '2026-10-01')->count())->toBe(2);
});

it('widget do painel mostra as estouradas e some quando não há', function () {
    expect(BudgetAlertsWidget::canView())->toBeTrue();

    Livewire::test(BudgetAlertsWidget::class)
        ->assertSee('Mercado')
        ->assertSee('R$ 100,00')
        ->assertSee('120,0%');

    app(SaveBudget::class)->execute($this->household->id, $this->mercado->id, Carbon::parse('2026-09-01'), 100000);

    expect(BudgetAlertsWidget::canView())->toBeFalse();
});
