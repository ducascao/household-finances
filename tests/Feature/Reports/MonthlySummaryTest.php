<?php

use App\Domain\Accounts\CreateAccount;
use App\Domain\CreditCard\CreateInstallmentPurchase;
use App\Domain\Household\CreateHousehold;
use App\Domain\Reports\MonthlySummary;
use App\Domain\Transactions\CreateTransaction;
use App\Domain\Transfers\CreateTransfer;
use App\Models\Account;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');

    $this->household = app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $this->eduardo = User::where('email', 'eduardo@example.com')->sole();
    $this->maria = User::factory()->inHousehold($this->household)->create();

    $this->joint = Account::factory()->ownedBy($this->eduardo)->shared()->create(['name' => 'Conjunta', 'initial_balance' => 100000]);
    $this->personal = Account::factory()->ownedBy($this->eduardo)->private()->create(['name' => 'Pessoal', 'initial_balance' => 0]);
    $this->card = app(CreateAccount::class)->execute($this->eduardo, [
        'name' => 'Cartão', 'type' => 'credit_card', 'visibility' => 'shared', 'currency' => 'BRL',
        'card_closing_day' => 15, 'card_due_day' => 25, 'card_limit' => 500000,
    ]);

    $this->category = fn (string $name) => Category::where('household_id', $this->household->id)->where('name', $name)->sole();
    $this->add = fn (Account $account, string $category, int $amount, array $data = []) => app(CreateTransaction::class)->execute($this->eduardo, [
        'account_id' => $account->id, 'category_id' => ($this->category)($category)->id,
        'amount' => $amount, 'date' => '2026-09-10', 'description' => $category, ...$data,
    ]);

    ($this->add)($this->joint, 'Salário', 800000);                                            // receita paga
    ($this->add)($this->joint, 'Salário', 50000, ['status' => 'scheduled', 'due_date' => '2026-09-28']); // receita prevista
    ($this->add)($this->joint, 'Aluguel', 280000);                                            // despesa paga
    ($this->add)($this->joint, 'Luz', 20000, ['status' => 'scheduled', 'due_date' => '2026-09-25']);     // despesa prevista
    ($this->add)($this->joint, 'Mercado', 60000);
    ($this->add)($this->joint, 'Mercado', 5000, ['is_refund' => true]);                       // estorno abate
    ($this->add)($this->personal, 'Restaurante', 12000);                                      // privada do Eduardo
    ($this->add)($this->card, 'Farmácia', 9000, ['date' => '2026-09-10']);                    // fatura de setembro
    ($this->add)($this->card, 'Farmácia', 7000, ['date' => '2026-09-16']);                    // fatura de outubro
    ($this->add)($this->joint, 'Aluguel', 280000, ['date' => '2026-08-10']);                  // outro mês
    app(CreateTransfer::class)->execute($this->eduardo, [                                     // fora dos relatórios
        'from_account_id' => $this->joint->id, 'to_account_id' => $this->personal->id, 'amount' => 30000, 'date' => '2026-09-12',
    ]);
});

/**
 * Consulta independente: soma por tipo de categoria dos lançamentos visíveis, sem transferências, na competência.
 */
function independentTotals(string $month, ?array $accountIds = null): array
{
    $rows = DB::table('transactions')
        ->join('categories', 'categories.id', '=', 'transactions.category_id')
        ->whereNull('transactions.transfer_id')
        ->where('transactions.competence_date', $month)
        ->when($accountIds, fn ($q) => $q->whereIn('transactions.account_id', $accountIds))
        ->selectRaw('categories.type, sum(transactions.amount) as total')
        ->groupBy('categories.type')
        ->pluck('total', 'type');

    return ['income' => (int) ($rows['income'] ?? 0), 'expense' => -(int) ($rows['expense'] ?? 0)];
}

it('totais do painel batem com a soma dos lançamentos filtrados', function () {
    $this->actingAs($this->eduardo);
    $totals = app(MonthlySummary::class)->totals(Carbon::parse('2026-09-01'));
    $expected = independentTotals('2026-09-01');

    expect($totals['income']['total'])->toBe($expected['income'])
        ->and($totals['expense']['total'])->toBe($expected['expense'])
        ->and($totals['income'])->toBe(['paid' => 800000, 'scheduled' => 50000, 'total' => 850000])
        ->and($totals['expense'])->toBe(['paid' => 280000 + 60000 - 5000 + 12000 + 9000, 'scheduled' => 20000, 'total' => 376000])
        ->and($totals['result'])->toBe(850000 - 376000);
});

it('cada usuário vê só os números das contas que enxerga', function () {
    $this->actingAs($this->maria);
    $totals = app(MonthlySummary::class)->totals(Carbon::parse('2026-09-01'));
    $expected = independentTotals('2026-09-01', [$this->joint->id, $this->card->id]);

    expect($totals['expense']['total'])->toBe($expected['expense'])
        ->and($totals['expense']['total'])->toBe(376000 - 12000);
});

it('compra no cartão conta no mês da fatura', function () {
    $this->actingAs($this->eduardo);

    expect(app(MonthlySummary::class)->totals(Carbon::parse('2026-10-01'))['expense']['total'])->toBe(7000);
});

it('agrupa despesas pela categoria principal, do maior para o menor, com percentual', function () {
    $this->actingAs($this->eduardo);
    $rows = app(MonthlySummary::class)->expensesByCategory(Carbon::parse('2026-09-01'));

    expect(array_map(fn ($r) => [$r['category']->name, $r['total']], $rows))->toBe([
        ['Moradia', 300000],
        ['Alimentação', 67000],
        ['Saúde', 9000],
    ])
        ->and(array_map(fn ($c) => [$c['category']->name, $c['total']], $rows[1]['children']))->toBe([['Mercado', 55000], ['Restaurante', 12000]])
        ->and(round(array_sum(array_column($rows, 'percent'))))->toBe(100.0)
        ->and(array_sum(array_column($rows, 'total')))->toBe(app(MonthlySummary::class)->totals(Carbon::parse('2026-09-01'))['expense']['total']);
});

it('evolução traz 12 meses, com meses vazios zerados e virada de ano', function () {
    $this->actingAs($this->eduardo);
    $series = app(MonthlySummary::class)->evolution(Carbon::parse('2027-01-01'));

    expect($series)->toHaveCount(12)
        ->and($series[0]['month']->format('Y-m'))->toBe('2026-02')
        ->and($series[11]['month']->format('Y-m'))->toBe('2027-01')
        ->and($series[11])->toMatchArray(['income' => 0, 'expense' => 0, 'result' => 0])
        ->and($series[6])->toMatchArray(['income' => 0, 'expense' => 280000, 'result' => -280000])
        ->and($series[7]['expense'])->toBe(376000)
        ->and($series[8]['expense'])->toBe(7000);
});

it('projeta o saldo: mês corrente com previstos, mês passado com o saldo real', function () {
    ($this->add)($this->joint, 'Internet', 10000, ['status' => 'scheduled', 'due_date' => '2026-09-05']); // atrasado
    ($this->add)($this->joint, 'Internet', 10000, ['status' => 'scheduled', 'due_date' => '2026-10-05']); // mês seguinte
    $this->actingAs($this->eduardo);

    $row = fn (string $month) => collect(app(MonthlySummary::class)->projection(Carbon::parse($month)))
        ->first(fn ($r) => $r['account']->is($this->joint));

    $today = 100000 + 800000 - 280000 - 60000 + 5000 - 280000 - 30000;

    expect($row('2026-09-01'))->toMatchArray([
        'today' => $today,
        'scheduled' => 50000 - 20000 - 10000,
        'projected' => $today + 50000 - 20000 - 10000,
    ])
        ->and($row('2026-10-01')['projected'])->toBe($today + 50000 - 20000 - 10000 - 10000)
        ->and($row('2026-08-01')['projected'])->toBe(100000 - 280000);
});

it('parcelas futuras entram na projeção do cartão no mês delas', function () {
    app(CreateInstallmentPurchase::class)->execute($this->eduardo, [
        'account_id' => $this->card->id, 'category_id' => ($this->category)('Casa')->id,
        'amount' => 30000, 'installments' => 3, 'date' => '2026-09-10', 'description' => 'Cadeira',
    ]);
    $this->actingAs($this->eduardo);

    $card = fn (string $month) => collect(app(MonthlySummary::class)->projection(Carbon::parse($month)))
        ->first(fn ($r) => $r['account']->is($this->card))['projected'];

    expect($card('2026-09-01'))->toBe(-(9000 + 7000 + 10000))
        ->and($card('2026-11-01'))->toBe(-(9000 + 7000 + 30000));
});
