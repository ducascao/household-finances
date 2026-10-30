<?php

use App\Domain\Household\CreateHousehold;
use App\Domain\Reports\MonthlySummary as Summary;
use App\Domain\Transactions\CreateTransaction;
use App\Filament\Monthly\BalanceProjectionWidget;
use App\Filament\Monthly\ChartFormatting;
use App\Filament\Monthly\EvolutionChart;
use App\Filament\Monthly\ExpensesByCategoryChart;
use App\Filament\Monthly\ExpensesByCategoryTable;
use App\Filament\Monthly\MonthTotalsWidget;
use App\Filament\Pages\MonthlySummary;
use App\Filament\Resources\Transactions\Pages\ListTransactions;
use App\Models\Account;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');

    $household = $this->household = app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $this->user = User::where('email', 'eduardo@example.com')->sole();
    $this->user->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
    $this->account = Account::factory()->ownedBy($this->user)->create(['name' => 'Conjunta', 'initial_balance' => 100000]);
    $this->mercado = Category::where('household_id', $household->id)->where('name', 'Mercado')->sole();
    $salary = Category::where('household_id', $household->id)->where('name', 'Salário')->sole();

    $add = fn (Category $category, int $amount, string $date) => app(CreateTransaction::class)->execute($this->user, [
        'account_id' => $this->account->id, 'category_id' => $category->id, 'amount' => $amount, 'date' => $date, 'description' => $category->name,
    ]);

    $this->septemberMarket = $add($this->mercado, 45000, '2026-09-10');
    $add($salary, 500000, '2026-09-05');
    $this->augustMarket = $add($this->mercado, 30000, '2026-08-10');

    $this->actingAs($this->user);
});

it('abre a página e mostra os números do mês escolhido', function () {
    $this->get(MonthlySummary::getUrl())->assertSuccessful()->assertSee('Resumo do mês');

    Livewire::test(MonthTotalsWidget::class, ['pageFilters' => ['month' => '2026-09']])
        ->assertSee('Setembro/2026')
        ->assertSee('R$ 5.000,00')
        ->assertSee('R$ 450,00')
        ->assertSee('R$ 4.550,00');

    Livewire::test(MonthTotalsWidget::class, ['pageFilters' => ['month' => '2026-08']])
        ->assertSee('Agosto/2026')
        ->assertSee('-R$ 300,00');
});

it('seletor e botões só mostram meses com lançamentos (pagos ou previstos) e o mês atual', function () {
    $other = Account::factory()->ownedBy(User::factory()->inHousehold($this->household)->create())->private()->create();
    $add = fn (Account $account, string $date) => app(CreateTransaction::class)->execute($account->owner, [
        'account_id' => $account->id, 'category_id' => $this->mercado->id, 'amount' => -1000, 'date' => $date, 'description' => 'Mercado',
    ]);
    $add($this->account, '2026-12-05');   // previsto, futuro
    $add($this->account, '2025-03-05');   // antigo, com meses vazios no meio
    $add($other, '2024-01-05');           // conta pessoal de outra pessoa: não aparece

    expect(Summary::monthsWithEntries()->map->format('Y-m')->all())
        ->toBe(['2026-12', '2026-09', '2026-08', '2025-03']);

    Livewire::test(MonthlySummary::class)
        ->assertSet('filters.month', '2026-09')
        ->assertSee('Dezembro/2026')
        ->assertDontSee('Outubro/2026')
        ->assertDontSee('Janeiro/2024')
        ->callAction('previousMonth')
        ->assertSet('filters.month', '2026-08')
        ->callAction('previousMonth')
        ->assertSet('filters.month', '2025-03')
        ->assertActionDisabled('previousMonth')
        ->callAction('nextMonth')
        ->callAction('nextMonth')
        ->callAction('nextMonth')
        ->assertSet('filters.month', '2026-12')
        ->assertActionDisabled('nextMonth')
        ->callAction('currentMonth')
        ->assertSet('filters.month', '2026-09');
});

it('renderiza gráficos, detalhe por categoria e projeção', function () {
    Livewire::test(ExpensesByCategoryChart::class, ['pageFilters' => ['month' => '2026-09']])->assertSuccessful();
    Livewire::test(EvolutionChart::class, ['pageFilters' => ['month' => '2026-09']])->assertSuccessful();

    Livewire::test(ExpensesByCategoryTable::class, ['pageFilters' => ['month' => '2026-09']])
        ->assertSee('Alimentação')
        ->assertSee('↳ Mercado')
        ->assertSee('100,0%');

    Livewire::test(BalanceProjectionWidget::class, ['pageFilters' => ['month' => '2026-09']])
        ->assertSee('Conjunta')
        ->assertSee('R$ 5.250,00')
        ->assertSee('Total BRL');
});

it('o detalhe da categoria abre os lançamentos filtrados por categoria e competência', function () {
    Livewire::withQueryParams(['filters' => [
        'category_id' => ['value' => (string) $this->mercado->id],
        'competence' => ['value' => '2026-09'],
    ]])->test(ListTransactions::class)
        ->assertCanSeeTableRecords([$this->septemberMarket])
        ->assertCanNotSeeTableRecords([$this->augustMarket]);
});

it('o donut de gastos usa uma cor por categoria e junta o excedente em "Outras" (cinza)', function () {
    $rows = array_map(
        fn (int $i): array => ['category' => new Category(['name' => "Cat {$i}"]), 'total' => (10 - $i) * 1000],
        range(1, 9),
    );

    $data = ExpensesByCategoryChart::donut($rows);
    $dataset = $data['datasets'][0];

    expect($data['labels'])->toHaveCount(8)
        ->and($data['labels'][7])->toBe('Outras')
        ->and($dataset['data'][7])->toEqual(30)
        ->and($dataset['backgroundColor'][0])->toBe(ChartFormatting::CATEGORICAL[0])
        ->and($dataset['backgroundColor'][6])->toBe(ChartFormatting::CATEGORICAL[6])
        ->and($dataset['backgroundColor'][7])->toBe(ChartFormatting::OTHER);

    $few = ExpensesByCategoryChart::donut(array_slice($rows, 0, 3));

    expect($few['datasets'][0]['backgroundColor'])->toBe(array_slice(ChartFormatting::CATEGORICAL, 0, 3));
});
