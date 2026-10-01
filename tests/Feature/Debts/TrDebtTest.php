<?php

use App\Domain\Debts\DebtSummary;
use App\Domain\Debts\ManageDebts;
use App\Domain\Debts\ReferenceRate;
use App\Domain\Household\CreateHousehold;
use App\Domain\NetWorth\NetWorthCalculator;
use App\Domain\NetWorth\NetWorthScope;
use App\Domain\Transactions\MarkAsPaid;
use App\Enums\PrepaymentMode;
use App\Enums\RatePeriod;
use App\Jobs\FetchTr;
use App\Models\Account;
use App\Models\Category;
use App\Models\Debt;
use App\Models\DebtInstallment;
use App\Models\InterestRate;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Carbon::setTestNow('2026-10-01 10:00:00');
    config(['services.bcb.url' => 'https://bcb.test']);

    $this->household = app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $this->user = User::where('email', 'eduardo@example.com')->sole();
    $this->maria = User::factory()->inHousehold($this->household)->create();
    $this->account = Account::factory()->ownedBy($this->user)->private()->create(['name' => 'Conta corrente', 'initial_balance' => 1000000]);
    $this->category = Category::where('household_id', $this->household->id)->where('name', 'Moradia')->sole();

    // TR (série 226) dos períodos que começam no dia 10, de março a setembro de 2026
    foreach (['2026-03-10' => '0.1708', '2026-04-10' => '0.1706', '2026-05-10' => '0.1690', '2026-06-10' => '0.1716',
        '2026-07-10' => '0.1714', '2026-08-10' => '0.1691', '2026-09-10' => '0.1661'] as $date => $rate) {
        InterestRate::create(['series' => InterestRate::TR, 'date' => $date, 'rate' => $rate]);
    }

    $this->create = fn (array $data = []) => app(ManageDebts::class)->create($this->user, [
        'name' => 'Financiamento imobiliário', 'creditor' => 'Banco Exemplo', 'system' => 'sac',
        'principal' => 15000000, 'rate' => '8,25', 'rate_period' => 'annual_effective', 'installments_count' => 200,
        'first_due_date' => '2026-04-10', 'tr_correction' => true, 'insurance_rate' => '0,0150', 'monthly_fee' => 4000,
        'payment_account_id' => $this->account->id, 'category_id' => $this->category->id, ...$data,
    ]);

    $this->installment = fn (Debt $debt, int $number) => DebtInstallment::where('debt_id', $debt->id)->where('number', $number)->sole();
    $this->pay = function (Debt $debt, int $number): void {
        $installment = ($this->installment)($debt, $number);
        app(MarkAsPaid::class)->execute($this->user, Transaction::findOrFail($installment->transaction_id), $installment->due_date);
    };
});

it('aceite: SAC com TR, seguro sobre o saldo corrigido e encargo fixo (abr–out/2026)', function () {
    $debt = ($this->create)();
    // Esperados calculados fora do app (script independente com a mesma fórmula; o mesmo cálculo, com os parâmetros
    // de um financiamento real que ficam fora do repositório, bateu com os débitos do banco com até 2 centavos).
    $expected = [180971, 180762, 180551, 180342, 180131, 179915, 179694];

    expect($debt->monthly_rate)->toBe('0.66279668');

    foreach ($expected as $index => $total) {
        $installment = ($this->installment)($debt, $index + 1);

        expect($installment->total)->toBe($total, "parcela {$installment->number}");
    }

    // Parcela de outubro: saldo corrigido pela TR de 0,1661%, amortização = saldo corrigido ÷ 194
    $october = ($this->installment)($debt, 7);
    expect($october->correction)->toBe(24416)
        ->and($october->amortization)->toBe(75896)
        ->and($october->interest)->toBe(97589)
        ->and($october->charges)->toBe(6209);

    // Pagas as 6 primeiras (até setembro): saldo devedor com a correção das parcelas pagas
    foreach (range(1, 6) as $number) {
        ($this->pay)($debt, $number);
    }

    expect((new DebtSummary($debt->refresh()))->outstanding())->toBe(14699411)
        ->and((new DebtSummary($debt))->corrected())->toBe(25620 + 25506 + 25182 + 25484 + 25368 + 24943);

    // Patrimônio usa o mesmo saldo (com a correção)
    $debts = app(NetWorthCalculator::class)->at(NetWorthScope::person($this->user), today())->debtItems;
    expect($debts['Financiamento imobiliário'])->toBe((new DebtSummary($debt))->outstanding());
});

it('parcelas futuras usam a última TR conhecida e são recalculadas quando sai a TR nova, sem recriar o lançamento', function () {
    $debt = ($this->create)();
    $november = ($this->installment)($debt, 8);       // vence 10/11: TR do período de 10/10 ainda não saiu
    $transactionId = $november->transaction_id;

    expect((float) app(ReferenceRate::class)->percentFor($november->due_date))->toBe(0.1661)
        ->and($transactionId)->not->toBeNull();

    Http::fake(['bcb.test/*' => Http::response([['data' => '10/10/2026', 'dataFim' => '10/11/2026', 'valor' => '0.2000']])]);
    Carbon::setTestNow('2026-10-10 10:00:00');
    (new FetchTr)->handle(app(ReferenceRate::class), app(ManageDebts::class));

    $updated = ($this->installment)($debt, 8);
    $transaction = Transaction::findOrFail($transactionId);

    expect($updated->transaction_id)->toBe($transactionId)
        ->and($updated->correction)->toBeGreaterThan($november->correction)
        ->and($transaction->amount->getMinorAmount()->toInt())->toBe(-$updated->total)
        ->and(DebtInstallment::where('debt_id', $debt->id)->count())->toBe(200);
});

it('a TR é buscada no Banco Central (série 226) e o job está agendado', function () {
    Http::fake(['bcb.test/*' => Http::response([
        ['data' => '01/10/2026', 'dataFim' => '01/11/2026', 'valor' => '0.1650'],
        ['data' => '02/10/2026', 'dataFim' => '02/11/2026', 'valor' => '0.1648'],
    ])]);

    expect(app(ReferenceRate::class)->fetch(Carbon::parse('2026-10-01'), today()))->toBe(2)
        ->and(app(ReferenceRate::class)->fetch(Carbon::parse('2026-10-01'), today()))->toBe(2)
        ->and(InterestRate::where('series', InterestRate::TR)->count())->toBe(9);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'bcdata.sgs.226/dados'));

    $events = collect(app(Schedule::class)->events())->filter(fn ($event): bool => str_contains((string) $event->description, FetchTr::class));
    expect($events)->toHaveCount(1);
});

it('converte a taxa anual efetiva e nominal para a mensal', function () {
    expect(RatePeriod::AnnualEffective->toMonthly('8.25'))->toBe('0.66279668')
        ->and(RatePeriod::AnnualNominal->toMonthly('8.25'))->toBe('0.68750000')
        ->and(RatePeriod::Monthly->toMonthly('0.95'))->toBe('0.95000000');

    $monthly = app(ManageDebts::class)->preview(['name' => 'x', 'creditor' => 'x', 'payment_account_id' => 0, 'category_id' => 0, 'system' => 'price',
        'principal' => 1000000, 'rate' => '0.66279668', 'rate_period' => 'monthly', 'installments_count' => 12, 'first_due_date' => '2026-11-10']);
    $annual = app(ManageDebts::class)->preview(['name' => 'x', 'creditor' => 'x', 'payment_account_id' => 0, 'category_id' => 0, 'system' => 'price',
        'principal' => 1000000, 'rate' => '8,25', 'rate_period' => 'annual_effective', 'installments_count' => 12, 'first_due_date' => '2026-11-10']);

    expect($annual[0]->total())->toBe($monthly[0]->total());
});

it('encargos entram na parcela mas não no saldo; sem TR o cálculo de juros não muda', function () {
    $debt = ($this->create)(['system' => 'price', 'tr_correction' => false, 'insurance_rate' => '0', 'monthly_fee' => 2500,
        'principal' => 1000000, 'rate' => '1', 'rate_period' => 'monthly', 'installments_count' => 12, 'first_due_date' => '2026-10-15']);
    $first = ($this->installment)($debt, 1);

    // Price R$ 10.000 a 1% em 12x: parcela 888,49 + R$ 25 de encargos
    expect($first->amortization + $first->interest)->toBe(88849)
        ->and($first->charges)->toBe(2500)
        ->and($first->total)->toBe(91349)
        ->and(Transaction::findOrFail($first->transaction_id)->amount->getMinorAmount()->toInt())->toBe(-91349);

    Carbon::setTestNow('2026-10-15 10:00:00');
    ($this->pay)($debt, 1);
    expect((new DebtSummary($debt))->outstanding())->toBe(1000000 - $first->amortization);
});

it('ajustar saldo devedor: grava a diferença e recalcula as parcelas não pagas com o mesmo prazo', function () {
    $debt = ($this->create)();
    foreach (range(1, 6) as $number) {
        ($this->pay)($debt, $number);
    }

    // Saldo informado pelo banco R$ 1.234,56 abaixo do calculado (146.994,11)
    app(ManageDebts::class)->adjustBalance($this->user, $debt, today(), 14575955);

    $summary = new DebtSummary($debt->refresh());
    expect($summary->outstanding())->toBe(14575955)
        ->and($summary->unpaid())->toHaveCount(194)
        ->and($debt->installments_count)->toBe(200)
        // outubro, recalculado a partir de 145.759,55 (esperado calculado fora do app)
        ->and(($this->installment)($debt, 7)->total)->toBe(178218);

    $debts = app(NetWorthCalculator::class)->at(NetWorthScope::person($this->user), today())->debtItems;
    expect($debts['Financiamento imobiliário'])->toBe(14575955);

    expect(fn () => app(ManageDebts::class)->adjustBalance($this->user, $debt, today()->addDay(), 1))->toThrow(ValidationException::class)
        ->and(fn () => app(ManageDebts::class)->adjustBalance($this->maria, $debt, today(), 1))->toThrow(ValidationException::class);
});

it('amortização extraordinária com TR reduzindo o prazo recalcula sem erro', function () {
    $debt = ($this->create)();
    foreach (range(1, 6) as $number) {
        ($this->pay)($debt, $number);
    }

    app(ManageDebts::class)->prepay($this->user, $debt, today(), 2000000, PrepaymentMode::ReduceTerm);

    expect($debt->refresh()->installments_count)->toBeLessThan(200)
        ->and((new DebtSummary($debt))->unpaid()->last()?->balance_after)->toBe(0);
});

it('tabela informada não aceita correção pela TR', function () {
    expect(fn () => ($this->create)(['system' => 'custom', 'rows' => [['due_date' => '2026-10-10', 'amortization' => 15000000, 'interest' => 0]]]))
        ->toThrow(ValidationException::class);
});
