<?php

use App\Domain\Accounts\CreateAccount;
use App\Domain\Budgets\BudgetReport;
use App\Domain\Budgets\CopyPreviousMonth;
use App\Domain\Budgets\SaveBudget;
use App\Domain\Household\CreateHousehold;
use App\Domain\Transactions\CreateTransaction;
use App\Domain\Transfers\CreateTransfer;
use App\Enums\BudgetStatus;
use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');

    $this->household = app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $this->eduardo = User::where('email', 'eduardo@example.com')->sole();
    $this->maria = User::factory()->inHousehold($this->household)->create();
    $this->joint = Account::factory()->ownedBy($this->eduardo)->shared()->create();
    $this->personal = Account::factory()->ownedBy($this->eduardo)->private()->create();

    $this->category = fn (string $name) => Category::where('household_id', $this->household->id)->where('name', $name)->sole();
    $this->budget = fn (string $name, ?int $amount, string $month = '2026-09-01') => app(SaveBudget::class)
        ->execute($this->household->id, ($this->category)($name)->id, Carbon::parse($month), $amount);
    $this->spend = fn (string $name, int $amount, array $data = []) => app(CreateTransaction::class)->execute($this->eduardo, [
        'account_id' => $this->joint->id, 'category_id' => ($this->category)($name)->id,
        'amount' => $amount, 'date' => '2026-09-10', 'description' => $name, ...$data,
    ]);
    $this->report = fn (string $month = '2026-09-01') => collect(app(BudgetReport::class)->forMonth($this->household->id, Carbon::parse($month)))
        ->keyBy(fn ($row) => $row->category->name);
});

it('realizado usa a competência: compra no cartão conta no mês da fatura', function () {
    $card = app(CreateAccount::class)->execute($this->eduardo, [
        'name' => 'Cartão', 'type' => 'credit_card', 'visibility' => 'shared', 'currency' => 'BRL',
        'card_closing_day' => 15, 'card_due_day' => 25, 'card_limit' => 500000,
    ]);
    ($this->budget)('Mercado', 100000);
    ($this->budget)('Mercado', 100000, '2026-10-01');
    ($this->spend)('Mercado', 30000, ['account_id' => $card->id, 'date' => '2026-09-10']); // fatura de setembro
    ($this->spend)('Mercado', 20000, ['account_id' => $card->id, 'date' => '2026-09-16']); // compra em setembro, fatura de outubro
    $this->actingAs($this->eduardo);

    $september = ($this->report)()['Alimentação']->children[0];
    $october = ($this->report)('2026-10-01')['Alimentação']->children[0];

    expect($september->category->name)->toBe('Mercado')
        ->and($september->paid)->toBe(30000)
        ->and($october->paid)->toBe(20000);
});

it('principal soma o gasto das subcategorias e herda o orçado delas quando não tem valor próprio', function () {
    ($this->budget)('Mercado', 80000);
    ($this->budget)('Restaurante', 20000);
    ($this->spend)('Mercado', 50000);
    ($this->spend)('Restaurante', 15000);
    ($this->spend)('Delivery', 5000);
    $this->actingAs($this->eduardo);

    $food = ($this->report)()['Alimentação'];

    expect($food->budget)->toBeNull()
        ->and($food->planned)->toBe(100000)
        ->and($food->paid)->toBe(70000)
        ->and($food->percent())->toBe(70.0)
        ->and(collect($food->children)->map(fn ($c) => $c->category->name)->all())->toBe(['Delivery', 'Mercado', 'Restaurante']);
});

it('avisa quando as subcategorias somam mais que o orçado da principal', function () {
    ($this->budget)('Moradia', 300000);
    ($this->budget)('Aluguel', 280000);
    ($this->budget)('Luz', 30000);
    $this->actingAs($this->eduardo);

    expect(($this->report)()['Moradia'])
        ->planned->toBe(300000)
        ->childrenOverBudget->toBeTrue();
});

it('situação pelos limites de 80% e 100%, com previsto, estorno e sem transferência', function (int $spent, BudgetStatus $expected) {
    ($this->budget)('Lazer', 100000);
    ($this->spend)('Viagens', $spent + 5000);
    ($this->spend)('Viagens', 5000, ['is_refund' => true]);
    app(CreateTransfer::class)->execute($this->eduardo, [
        'from_account_id' => $this->joint->id, 'to_account_id' => $this->personal->id, 'amount' => 999999, 'date' => '2026-09-10',
    ]);
    $this->actingAs($this->eduardo);

    expect(($this->report)()['Lazer']->status())->toBe($expected);
})->with([
    '79,9%' => [79900, BudgetStatus::Within],
    '80%' => [80000, BudgetStatus::Attention],
    '100%' => [100000, BudgetStatus::Attention],
    '100,1%' => [100100, BudgetStatus::Exceeded],
]);

it('previsto entra no comprometido e antecipa o estouro', function () {
    ($this->budget)('Luz', 20000);
    ($this->spend)('Luz', 15000);
    ($this->spend)('Luz', 10000, ['status' => 'scheduled', 'due_date' => '2026-09-28']);
    $this->actingAs($this->eduardo);

    $light = ($this->report)()['Moradia']->children[0];

    expect($light)->paid->toBe(15000)->scheduled->toBe(10000)
        ->and($light->committed())->toBe(25000)
        ->and($light->available())->toBe(-5000)
        ->and($light->status())->toBe(BudgetStatus::Exceeded);
});

it('lista só categorias com orçamento ou gasto', function () {
    ($this->budget)('Educação', 50000);
    ($this->spend)('Farmácia', 3000);
    $this->actingAs($this->eduardo);

    expect(($this->report)()->keys()->sort()->values()->all())->toBe(['Educação', 'Saúde']);
});

it('realizado respeita a visibilidade de quem vê', function () {
    ($this->budget)('Restaurante', 50000);
    ($this->spend)('Restaurante', 10000);
    ($this->spend)('Restaurante', 30000, ['account_id' => $this->personal->id]);

    $this->actingAs($this->eduardo);
    $forEduardo = ($this->report)()['Alimentação']->paid;
    $this->actingAs($this->maria);
    $forMaria = ($this->report)()['Alimentação']->paid;

    expect($forEduardo)->toBe(40000)->and($forMaria)->toBe(10000);
});

it('copia do mês anterior sem sobrescrever e sem duplicar', function () {
    ($this->budget)('Mercado', 80000, '2026-08-01');
    ($this->budget)('Luz', 20000, '2026-08-01');
    ($this->budget)('Luz', 25000);

    expect(app(CopyPreviousMonth::class)->execute($this->household->id, Carbon::parse('2026-09-01')))->toBe(1)
        ->and(app(CopyPreviousMonth::class)->execute($this->household->id, Carbon::parse('2026-09-01')))->toBe(0);

    $september = Budget::whereDate('month', '2026-09-01')->with('category')->get()
        ->mapWithKeys(fn ($b) => [$b->category->name => $b->amount->getMinorAmount()->toInt()])->sortKeys()->all();

    expect($september)->toBe(['Luz' => 25000, 'Mercado' => 80000]);
});

it('valor vazio remove e categoria de receita é recusada', function () {
    ($this->budget)('Mercado', 80000);
    ($this->budget)('Mercado', null);

    expect(Budget::count())->toBe(0)
        ->and(fn () => ($this->budget)('Salário', 1000))->toThrow(ValidationException::class, 'despesa');
});

it('lista as estouradas do mês', function () {
    ($this->budget)('Mercado', 10000);
    ($this->budget)('Luz', 10000);
    ($this->spend)('Mercado', 20000);
    ($this->spend)('Luz', 9000);
    $this->actingAs($this->eduardo);

    expect(collect(app(BudgetReport::class)->withStatus($this->household->id, Carbon::parse('2026-09-01'), BudgetStatus::Exceeded))
        ->map(fn ($row) => $row->category->name)->all())->toBe(['Alimentação', 'Mercado']);
});
