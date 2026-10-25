<?php

use App\Domain\Accounts\CreateAccount;
use App\Domain\CreditCard\InvoiceCategoryBreakdown;
use App\Domain\CreditCard\InvoiceTotals;
use App\Domain\Transactions\CreateTransaction;
use App\Filament\Resources\Invoices\Widgets\InvoiceExpensesChart;
use App\Filament\Resources\Invoices\Widgets\InvoiceExpensesTable;
use App\Filament\Resources\Transactions\Pages\ListTransactions;
use App\Filament\Resources\Transactions\TransactionResource;
use App\Models\Account;
use App\Models\Category;
use App\Models\Household;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');

    $this->household = Household::factory()->create();
    $this->eduardo = User::factory()->withTwoFactor()->inHousehold($this->household)->create(['name' => 'Eduardo']);
    $this->maria = User::factory()->withTwoFactor()->inHousehold($this->household)->create(['name' => 'Maria']);

    $in = ['household_id' => $this->household->id];
    $this->food = Category::factory()->expense()->create([...$in, 'name' => 'Alimentação']);
    $this->market = Category::factory()->expense()->childOf($this->food)->create([...$in, 'name' => 'Mercado']);
    $this->restaurant = Category::factory()->expense()->childOf($this->food)->create([...$in, 'name' => 'Restaurante']);
    $this->transport = Category::factory()->expense()->create([...$in, 'name' => 'Transporte']);
    $this->cashback = Category::factory()->income()->create([...$in, 'name' => 'Cashback']);

    // Fecha dia 3 e vence dia 10: compras de 20/09 a 02/10 caem na fatura de Outubro/2026.
    $this->newCard = fn (string $visibility) => app(CreateAccount::class)->execute($this->eduardo, [
        'name' => "Cartão {$visibility}", 'type' => 'credit_card', 'visibility' => $visibility, 'currency' => 'BRL',
        'card_closing_day' => 3, 'card_due_day' => 10, 'card_limit' => 500000,
    ]);
    $this->card = ($this->newCard)('shared');

    $this->add = fn (Category $category, int $amount, string $date = '2026-09-25', ?Account $card = null, bool $refund = false) => app(CreateTransaction::class)
        ->execute($this->eduardo, [
            'account_id' => ($card ?? $this->card)->id, 'category_id' => $category->id, 'amount' => $amount,
            'date' => $date, 'description' => $category->name, 'is_refund' => $refund,
        ]);

    $this->invoice = ($this->add)($this->market, 30000)->invoice;
    ($this->add)($this->market, 10000);
    ($this->add)($this->restaurant, 8000);
    ($this->add)($this->transport, 12000);
    ($this->add)($this->restaurant, 3000, refund: true);
    ($this->add)($this->cashback, 1000);
    ($this->add)($this->transport, 5000, '2026-10-05');            // fatura de Novembro
    ($this->add)($this->market, 99900, card: ($this->newCard)('private')); // outro cartão
});

it('soma por categoria, com estorno abatendo e créditos à parte, bate com o total da fatura', function () {
    $this->actingAs($this->eduardo);

    $breakdown = InvoiceCategoryBreakdown::of($this->invoice);
    $rows = collect($breakdown['categories'])->map(fn (array $root): array => [
        $root['category']->name, $root['total'], $root['percent'],
        collect($root['children'])->map(fn (array $child): array => [$child['category']->name, $child['total']])->all(),
    ])->all();

    // Alimentação = 300 + 100 (Mercado) + 80 − 30 (Restaurante) = 450; Transporte = 120; gastos = 570.
    expect($rows)->toBe([
        ['Alimentação', 45000, 78.9, [['Mercado', 40000], ['Restaurante', 5000]]],
        ['Transporte', 12000, 21.1, []],
    ])
        ->and($breakdown['credits'])->toBe(-1000)
        ->and($breakdown['total'])->toBe(56000)
        ->and($breakdown['total'])->toBe(InvoiceTotals::amountDue($this->invoice)->getMinorAmount()->toInt());
});

it('fatura sem itens vem vazia', function () {
    $this->actingAs($this->eduardo);
    $empty = Invoice::factory()->create([
        'household_id' => $this->household->id, 'credit_card_id' => $this->invoice->credit_card_id,
        'reference_month' => '2027-03-01', 'closing_date' => '2027-03-03', 'due_date' => '2027-03-10',
    ]);

    expect(InvoiceCategoryBreakdown::of($empty))->toBe(['categories' => [], 'credits' => 0, 'total' => 0]);

    Livewire::test(InvoiceExpensesTable::class, ['record' => $empty])->assertSee('Nenhuma compra nesta fatura');
});

it('a tela da fatura de cartão compartilhado mostra o gráfico e o detalhe para os dois', function (string $who) {
    $this->actingAs($this->{$who});

    $this->get(route('filament.app.resources.invoices.view', $this->invoice))
        ->assertSuccessful()
        ->assertSee('Gastos por categoria')
        ->assertSee('Detalhe por categoria');

    Livewire::test(InvoiceExpensesChart::class, ['record' => $this->invoice])->assertSuccessful();

    $url = TransactionResource::getUrl('index', ['filters' => [
        'account_id' => ['values' => [(string) $this->card->id]],
        'category_id' => ['value' => (string) $this->food->id],
        'competence' => ['value' => '2026-10'],
    ]]);

    Livewire::test(InvoiceExpensesTable::class, ['record' => $this->invoice])
        ->assertSeeInOrder(['Alimentação', 'R$ 450,00', '78,9%', '↳ Mercado', 'R$ 400,00', '↳ Restaurante', 'R$ 50,00',
            'Transporte', 'R$ 120,00', '21,1%', 'Créditos', '-R$ 10,00', 'Total da fatura', 'R$ 560,00'])
        ->assertSee($url);
})->with(['eduardo', 'maria']);

it('o link da categoria abre só os lançamentos daquele cartão, categoria e fatura', function () {
    $this->actingAs($this->eduardo);
    $items = $this->invoice->transactions()->get();

    Livewire::withQueryParams(['filters' => [
        'account_id' => ['values' => [(string) $this->card->id]],
        'category_id' => ['value' => (string) $this->food->id],
        'competence' => ['value' => '2026-10'],
    ]])->test(ListTransactions::class)
        ->assertCanSeeTableRecords($items->whereIn('category_id', [$this->market->id, $this->restaurant->id]))
        ->assertCanNotSeeTableRecords($items->whereNotIn('category_id', [$this->market->id, $this->restaurant->id]));
});

it('fatura de cartão pessoal não abre para outra pessoa do lar e não expõe os gastos', function () {
    $private = Account::query()->withoutGlobalScopes()->where('name', 'Cartão private')->sole();
    $privateInvoice = Invoice::withoutGlobalScopes()->whereHas('transactions', fn ($q) => $q->withoutGlobalScopes()->where('account_id', $private->id))->sole();

    $this->actingAs($this->eduardo);
    expect(InvoiceCategoryBreakdown::of($privateInvoice)['total'])->toBe(99900);

    $this->actingAs($this->maria);
    $this->get(route('filament.app.resources.invoices.view', $privateInvoice))->assertNotFound();
    expect(InvoiceCategoryBreakdown::of($privateInvoice))->toBe(['categories' => [], 'credits' => 0, 'total' => 0]);
});
