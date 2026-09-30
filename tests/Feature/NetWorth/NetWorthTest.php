<?php

use App\Domain\Accounts\CreateAccount;
use App\Domain\Debts\GenerateDebtInstallments;
use App\Domain\Debts\ManageDebts;
use App\Domain\Household\CreateHousehold;
use App\Domain\Investments\ManageOperations;
use App\Domain\Investments\PriceBook;
use App\Domain\Investments\SaveAsset;
use App\Domain\NetWorth\NetWorthCalculator;
use App\Domain\NetWorth\NetWorthScope;
use App\Domain\NetWorth\TakeSnapshots;
use App\Domain\Transactions\CreateTransaction;
use App\Domain\Transactions\MarkAsPaid;
use App\Enums\AccountType;
use App\Jobs\TakeNetWorthSnapshots;
use App\Models\Account;
use App\Models\Category;
use App\Models\DebtInstallment;
use App\Models\ExchangeRate;
use App\Models\NetWorthSnapshot;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-08-05 10:00:00');

    $this->household = app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $this->eduardo = User::where('email', 'eduardo@example.com')->sole();
    $this->maria = User::factory()->inHousehold($this->household)->create();
    $expense = Category::where('household_id', $this->household->id)->where('name', 'Mercado')->sole();
    $housing = Category::where('household_id', $this->household->id)->where('name', 'Moradia')->sole();

    // Conta conjunta R$ 10.000; conta pessoal do Eduardo R$ 2.000; corretora BRL R$ 1.000; corretora USD US$ 1.000
    $this->joint = Account::factory()->ownedBy($this->eduardo)->shared()->create(['name' => 'Conjunta', 'initial_balance' => 1000000]);
    Account::factory()->ownedBy($this->eduardo)->private()->create(['name' => 'Pessoal', 'initial_balance' => 200000]);
    $broker = Account::factory()->ownedBy($this->eduardo)->shared()->create(['name' => 'XP', 'type' => AccountType::Brokerage, 'initial_balance' => 100000]);
    Account::factory()->ownedBy($this->eduardo)->shared()->create(['name' => 'Avenue', 'type' => AccountType::Brokerage, 'currency' => 'USD', 'initial_balance' => 100000]);
    ExchangeRate::create(['currency' => 'USD', 'date' => '2026-08-28', 'rate' => '5.00']);

    // Ação: 100 × 10 (zera o caixa da XP), cotação 11 → R$ 1.100
    $petr = app(SaveAsset::class)->execute($this->eduardo, ['account_id' => $broker->id, 'type' => 'stock', 'ticker' => 'PETR4', 'name' => 'Petrobras']);
    app(ManageOperations::class)->register($this->eduardo, $petr, ['type' => 'buy', 'date' => '2026-08-05', 'quantity' => '100', 'unit_price' => '10']);
    app(PriceBook::class)->setManual($this->eduardo, $petr, Carbon::parse('2026-08-05'), '11');
    $this->petr = $petr;

    // Despesa de R$ 1.000 na conjunta
    app(CreateTransaction::class)->execute($this->eduardo, [
        'account_id' => $this->joint->id, 'category_id' => $expense->id, 'amount' => 100000, 'date' => '2026-08-05', 'description' => 'Mercado',
    ]);

    // Empréstimo Price R$ 10.000 a 1% em 12x (1º vencimento 15/08), pago da conjunta
    $this->debt = app(ManageDebts::class)->create($this->eduardo, [
        'name' => 'Empréstimo', 'creditor' => 'Banco', 'principal' => 1000000, 'monthly_rate' => '1', 'system' => 'price',
        'installments_count' => 12, 'first_due_date' => '2026-08-15', 'payment_account_id' => $this->joint->id, 'category_id' => $housing->id,
    ]);

    // Cartão compartilhado com compra de R$ 300
    $card = app(CreateAccount::class)->execute($this->eduardo, [
        'name' => 'Visa', 'type' => 'credit_card', 'visibility' => 'shared', 'currency' => 'BRL',
        'card_closing_day' => 25, 'card_due_day' => 5, 'card_limit' => 500000,
    ]);
    app(CreateTransaction::class)->execute($this->eduardo, [
        'account_id' => $card->id, 'category_id' => $expense->id, 'amount' => 30000, 'date' => '2026-08-05', 'description' => 'Loja',
    ]);

    $this->payInstallment = function (int $number, string $paidOn): void {
        $installment = DebtInstallment::where('debt_id', $this->debt->id)->where('number', $number)->sole();
        app(MarkAsPaid::class)->execute($this->eduardo, Transaction::findOrFail($installment->transaction_id), Carbon::parse($paidOn));
    };
});

it('compõe o patrimônio: contas, investimentos e dívidas, na visão do lar e da pessoa', function () {
    Carbon::setTestNow('2026-08-15 10:00:00');
    ($this->payInstallment)(1, '2026-08-15'); // amortiza 788,49; sai 888,49 da conjunta
    Carbon::setTestNow('2026-08-31 23:30:00');

    $household = app(NetWorthCalculator::class)->at(NetWorthScope::household($this->household), today());

    // contas: conjunta 10.000 − 1.000 − 888,49 = 8.111,51; Avenue US$ 1.000 × 5 = 5.000 (XP zerada fica de fora)
    // investimentos: 1.100; dívidas: empréstimo 10.000 − 788,49 = 9.211,51 + cartão 300
    expect($household->accountItems)->toBe(['Conjunta' => 811151, 'Avenue' => 500000])
        ->and($household->investmentItems)->toBe(['Ação' => 110000])
        ->and($household->debtItems)->toBe(['Cartão Visa' => 30000, 'Empréstimo' => 921151])
        ->and($household->netWorth())->toBe(1311151 + 110000 - 951151)
        ->and(app(NetWorthCalculator::class)->at(NetWorthScope::person($this->eduardo), today())->netWorth())->toBe($household->netWorth() + 200000)
        ->and(app(NetWorthCalculator::class)->at(NetWorthScope::person($this->maria), today())->netWorth())->toBe($household->netWorth());
});

it('recalcular um mês passado gera o mesmo valor que o snapshot original', function () {
    Carbon::setTestNow('2026-08-15 10:00:00');
    ($this->payInstallment)(1, '2026-08-15');
    Carbon::setTestNow('2026-08-31 23:30:00');
    (new TakeNetWorthSnapshots)->handle(app(TakeSnapshots::class));

    $original = NetWorthSnapshot::whereNull('user_id')->whereDate('month', '2026-08-01')->sole()->only(['accounts', 'investments', 'debts', 'net_worth']);

    // Setembro: nova parcela paga, nova despesa, nova cotação e novo câmbio.
    Carbon::setTestNow('2026-09-20 10:00:00');
    app(GenerateDebtInstallments::class)->execute($this->debt);
    ($this->payInstallment)(2, '2026-09-15');
    app(CreateTransaction::class)->execute($this->eduardo, [
        'account_id' => $this->joint->id, 'category_id' => Category::where('name', 'Mercado')->value('id'),
        'amount' => 50000, 'date' => '2026-09-10', 'description' => 'Mercado',
    ]);
    app(PriceBook::class)->setManual($this->eduardo, $this->petr, Carbon::parse('2026-09-19'), '15');
    ExchangeRate::create(['currency' => 'USD', 'date' => '2026-09-18', 'rate' => '6.00']);

    $this->artisan('app:net-worth', ['--month' => '2026-08'])->assertSuccessful();

    expect(NetWorthSnapshot::whereNull('user_id')->whereDate('month', '2026-08-01')->sole()->only(['accounts', 'investments', 'debts', 'net_worth']))
        ->toBe($original)
        ->and(NetWorthSnapshot::count())->toBe(3);
});

it('parcela paga depois da data não reduz a dívida naquela data', function () {
    Carbon::setTestNow('2026-09-02 10:00:00');
    ($this->payInstallment)(1, '2026-09-02'); // paga com atraso

    $august = app(NetWorthCalculator::class)->at(NetWorthScope::household($this->household), Carbon::parse('2026-08-31'));
    $september = app(NetWorthCalculator::class)->at(NetWorthScope::household($this->household), Carbon::parse('2026-09-02'));

    expect($august->debtItems['Empréstimo'])->toBe(1000000)
        ->and($september->debtItems['Empréstimo'])->toBe(921151);
});

it('grava uma fotografia por pessoa e uma do lar, sem duplicar, e o recálculo aceita um intervalo', function () {
    Carbon::setTestNow('2026-10-15 10:00:00');

    $this->artisan('app:net-worth', ['--from' => '2026-08'])->expectsOutputToContain('9 fotografia(s)')->assertSuccessful();
    $this->artisan('app:net-worth', ['--from' => '2026-08'])->assertSuccessful();

    expect(NetWorthSnapshot::count())->toBe(9)
        ->and(NetWorthSnapshot::whereNull('user_id')->orderBy('month')->pluck('month')->map->format('Y-m')->all())->toBe(['2026-08', '2026-09', '2026-10'])
        // lastDayOfMonth() grava o último dia do mês em que o agendamento é carregado (o scheduler recarrega a cada minuto).
        ->and(collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->description, TakeNetWorthSnapshots::class))?->expression)
        ->toMatch('/^30 23 (28|29|30|31) \* \*$/');
});

it('a visão do lar não inclui contas pessoais de ninguém', function () {
    $other = User::factory()->inHousehold($this->household)->create();
    Account::factory()->ownedBy($other)->private()->create(['name' => 'Secreta', 'initial_balance' => 99999900]);
    Carbon::setTestNow('2026-08-31 23:30:00');

    $household = app(NetWorthCalculator::class)->at(NetWorthScope::household($this->household), today());
    $eduardo = app(NetWorthCalculator::class)->at(NetWorthScope::person($this->eduardo), today());

    expect(array_keys($household->accountItems))->not->toContain('Secreta')
        ->and(array_keys($eduardo->accountItems))->not->toContain('Secreta')
        ->and(array_keys($eduardo->accountItems))->toContain('Pessoal')
        ->and(array_keys($household->accountItems))->not->toContain('Pessoal');
});
