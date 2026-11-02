<?php

use App\Domain\Accounts\CreateAccount;
use App\Domain\Household\CreateHousehold;
use App\Domain\Reports\DashboardSummary;
use App\Domain\Reports\MonthlySummary;
use App\Domain\Transactions\CreateTransaction;
use App\Domain\Transfers\CreateTransfer;
use App\Filament\Widgets\SummaryCardsWidget;
use App\Models\Account;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\User;
use Brick\Money\Money;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');

    $this->household = app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $this->eduardo = User::where('email', 'eduardo@example.com')->sole();
    $this->maria = User::factory()->inHousehold($this->household)->create();

    $this->joint = Account::factory()->ownedBy($this->eduardo)->shared()->create(['name' => 'Conjunta', 'initial_balance' => 100000]);
    $this->personal = Account::factory()->ownedBy($this->eduardo)->private()->create(['name' => 'Pessoal', 'initial_balance' => 0]);
    Account::factory()->ownedBy($this->eduardo)->shared()->create(['name' => 'Wise', 'currency' => 'EUR', 'initial_balance' => 50000]);
    Account::factory()->ownedBy($this->maria)->private()->create(['name' => 'Da Maria', 'initial_balance' => 70000]);
    Account::factory()->ownedBy($this->eduardo)->shared()->archived()->create(['name' => 'Antiga', 'initial_balance' => 99900]);

    $card = fn (User $owner, string $visibility) => app(CreateAccount::class)->execute($owner, [
        'name' => 'Cartão '.$owner->name, 'type' => 'credit_card', 'visibility' => $visibility, 'currency' => 'BRL',
        'card_closing_day' => 15, 'card_due_day' => 25, 'card_limit' => 500000,
    ]);
    $this->card = $card($this->eduardo, 'shared');
    $mariaCard = $card($this->maria, 'private');

    $category = fn (string $name) => Category::where('household_id', $this->household->id)->where('name', $name)->sole();
    $add = fn (User $user, Account $account, string $name, int $amount, array $data = []) => app(CreateTransaction::class)->execute($user, [
        'account_id' => $account->id, 'category_id' => $category($name)->id,
        'amount' => $amount, 'date' => '2026-09-10', 'description' => $name, ...$data,
    ]);

    $add($this->eduardo, $this->joint, 'Salário', 800000);                                                // receita paga
    $add($this->eduardo, $this->joint, 'Salário', 50000, ['status' => 'scheduled', 'due_date' => '2026-09-28']); // a receber
    $add($this->eduardo, $this->joint, 'Aluguel', 280000);                                                // despesa paga
    $add($this->eduardo, $this->joint, 'Luz', 20000, ['status' => 'scheduled', 'due_date' => '2026-09-25']);    // a pagar
    $add($this->eduardo, $this->personal, 'Restaurante', 12000);
    $add($this->eduardo, $this->card, 'Farmácia', 9000, ['date' => '2026-09-10']);                       // fatura que vence 25/09
    $add($this->eduardo, $this->card, 'Farmácia', 7000, ['date' => '2026-09-16']);                       // fatura que vence 25/10
    $add($this->maria, $mariaCard, 'Mercado', 4000, ['date' => '2026-09-05']);                           // cartão privado da Maria
    app(CreateTransfer::class)->execute($this->eduardo, [
        'from_account_id' => $this->joint->id, 'to_account_id' => $this->personal->id, 'amount' => 30000, 'date' => '2026-09-12',
    ]);
});

it('saldo geral soma as contas ativas que o usuário vê, sem cartões, e separa as outras moedas', function () {
    $this->actingAs($this->eduardo);
    $balance = app(DashboardSummary::class)->balance();

    // Conjunta 1.000 + 8.000 − 2.800 − 300 (transferência) = 5.900; Pessoal −120 + 300 = 180; a conta da Maria e a arquivada ficam de fora
    expect($balance['current']->isEqualTo(Money::ofMinor(608000, 'BRL')))->toBeTrue()
        // fim do mês: + 500 a receber − 200 a pagar
        ->and($balance['projected']->isEqualTo(Money::ofMinor(638000, 'BRL')))->toBeTrue()
        ->and(array_keys($balance['others']))->toBe(['EUR'])
        ->and($balance['others']['EUR']->isEqualTo(Money::ofMinor(50000, 'EUR')))->toBeTrue();

    $this->actingAs($this->maria);

    // A Maria vê as compartilhadas e a dela, não a pessoal do Eduardo
    expect(app(DashboardSummary::class)->balance()['current']->isEqualTo(Money::ofMinor(590000 + 70000, 'BRL')))->toBeTrue();
});

it('receitas e despesas do mês são as mesmas do Resumo do mês', function () {
    $this->actingAs($this->eduardo);

    expect(app(DashboardSummary::class)->month())->toBe(app(MonthlySummary::class)->totals(Carbon::parse('2026-09-01')))
        ->and(app(DashboardSummary::class)->month()['income'])->toBe(['paid' => 800000, 'scheduled' => 50000, 'total' => 850000]);
});

it('cartões somam a fatura atual (a mais antiga em aberto) de cada cartão visível', function () {
    $this->actingAs($this->eduardo);
    $cards = app(DashboardSummary::class)->cards();

    // Só a fatura de setembro do cartão compartilhado; a de outubro ainda não é a atual; o cartão da Maria é privado
    expect($cards['totals']['BRL']->isEqualTo(Money::ofMinor(9000, 'BRL')))->toBeTrue()
        ->and($cards['count'])->toBe(1)
        ->and($cards['next_due']?->toDateString())->toBe('2026-09-25');

    // Paga a de setembro: a atual passa a ser a de outubro
    Invoice::query()->whereDate('due_date', '2026-09-25')->sole()->forceFill(['paid_at' => now()])->save();
    $cards = app(DashboardSummary::class)->cards();

    expect($cards['totals']['BRL']->isEqualTo(Money::ofMinor(7000, 'BRL')))->toBeTrue()
        ->and($cards['next_due']?->toDateString())->toBe('2026-10-25');

    $this->actingAs($this->maria);
    $cards = app(DashboardSummary::class)->cards();

    // A Maria vê o compartilhado (outubro, 70,00) e o dela (setembro, 40,00)
    expect($cards['totals']['BRL']->isEqualTo(Money::ofMinor(11000, 'BRL')))->toBeTrue()
        ->and($cards['count'])->toBe(2)
        ->and($cards['next_due']?->toDateString())->toBe('2026-09-25');
});

it('o widget mostra os quatro cards com valores marcados para o modo privacidade', function () {
    $this->actingAs($this->eduardo);
    $html = Livewire::test(SummaryCardsWidget::class)->html();

    expect($html)->toContain('Saldo geral')->toContain('Receitas do mês')->toContain('Despesas do mês')->toContain('Cartões')
        ->and($html)->toContain('R$ 6.080,00')
        ->and($html)->toContain('fim do mês: R$ 6.380,00 · além de € 500,00')
        ->and($html)->toContain('1 fatura atual · vence 25/09')
        ->and(substr_count($html, 'fc-sensitive'))->toBeGreaterThanOrEqual(8);
});
