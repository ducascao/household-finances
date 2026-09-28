<?php

use App\Domain\Transactions\BillsSummary;
use App\Domain\Transactions\CreateTransaction;
use App\Domain\Transfers\CreateTransfer;
use App\Filament\Widgets\BillsOverviewWidget;
use App\Filament\Widgets\UpcomingBillsWidget;
use App\Models\Account;
use App\Models\Category;
use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');

    $this->household = Household::factory()->create();
    $this->eduardo = User::factory()->withTwoFactor()->inHousehold($this->household)->create();
    $this->maria = User::factory()->withTwoFactor()->inHousehold($this->household)->create();

    $this->joint = Account::factory()->ownedBy($this->eduardo)->shared()->create();
    $this->personalOfEduardo = Account::factory()->ownedBy($this->eduardo)->private()->create();
    $this->expense = Category::factory()->expense()->create(['household_id' => $this->household->id]);
    $this->income = Category::factory()->income()->create(['household_id' => $this->household->id]);

    $this->bill = fn (string $dueDate, int $amount, array $data = []) => app(CreateTransaction::class)->execute($this->eduardo, [
        'account_id' => $this->joint->id,
        'category_id' => $this->expense->id,
        'amount' => $amount,
        'status' => 'scheduled',
        'due_date' => $dueDate,
        'description' => "Conta {$dueDate}",
        ...$data,
    ]);

    ($this->bill)('2026-09-10', 10000);                                   // atrasado
    ($this->bill)('2026-09-19', 5000);                                    // atrasado
    ($this->bill)('2026-09-20', 2000);                                    // vence hoje
    ($this->bill)('2026-09-27', 3000);                                    // em 7 dias
    ($this->bill)('2026-09-28', 4000);                                    // fora dos 7 dias, no mês
    ($this->bill)('2026-09-25', 800000, ['category_id' => $this->income->id]); // receita prevista
    ($this->bill)('2026-10-02', 7000);                                    // mês seguinte
    ($this->bill)('2026-09-05', 99900, ['account_id' => $this->personalOfEduardo->id]); // privado do Eduardo, atrasado
    app(CreateTransaction::class)->execute($this->eduardo, [               // pago: não conta
        'account_id' => $this->joint->id, 'category_id' => $this->expense->id,
        'amount' => 1234, 'date' => '2026-09-05', 'due_date' => '2026-09-01', 'description' => 'Paga',
    ]);
    app(CreateTransfer::class)->execute($this->eduardo, [                  // transferência prevista: fora
        'from_account_id' => $this->joint->id, 'to_account_id' => $this->personalOfEduardo->id,
        'amount' => 55500, 'status' => 'scheduled', 'due_date' => '2026-09-15',
    ]);
});

function minor(array $totals): array
{
    return array_map(fn ($m) => $m->getMinorAmount()->toInt(), $totals);
}

it('calcula atrasados, vencendo em 7 dias e previsto do mês sem transferências nem contas alheias', function () {
    $this->actingAs($this->maria);
    $summary = app(BillsSummary::class);

    expect($summary->overdue()['count'])->toBe(2)
        ->and(minor($summary->overdue()['totals']))->toBe(['BRL' => -15000])
        ->and($summary->dueSoon()['count'])->toBe(3)
        ->and(minor($summary->dueSoon()['totals']))->toBe(['BRL' => -2000 - 3000 + 800000])
        ->and(minor($summary->forecastForMonth()['payable']))->toBe(['BRL' => -(10000 + 5000 + 2000 + 3000 + 4000)])
        ->and(minor($summary->forecastForMonth()['receivable']))->toBe(['BRL' => 800000]);
});

it('o dono da conta privada vê também os previstos dela', function () {
    $this->actingAs($this->eduardo);

    expect(app(BillsSummary::class)->overdue()['count'])->toBe(3)
        ->and(minor(app(BillsSummary::class)->overdue()['totals']))->toBe(['BRL' => -(15000 + 99900)]);
});

it('mostra os números e a lista no painel', function () {
    $this->actingAs($this->maria);

    Livewire::test(BillsOverviewWidget::class)
        ->assertSee('Atrasados')
        ->assertSee('-R$ 150,00')
        ->assertSee('2 lançamentos')
        ->assertSee('A receber: R$ 8.000,00');

    Livewire::test(UpcomingBillsWidget::class)
        ->assertSee('Conta 2026-09-10')
        ->assertSee('Conta 2026-09-27')
        ->assertDontSee('Conta 2026-09-28')
        ->assertDontSee('Conta 2026-09-05')
        ->assertDontSee('Transferência');
});
