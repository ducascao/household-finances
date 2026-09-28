<?php

use App\Domain\Accounts\CreateAccount;
use App\Domain\CreditCard\PayInvoice;
use App\Domain\Recurrences\CreateRecurrence;
use App\Domain\Transactions\BillsSummary;
use App\Domain\Transactions\CreateTransaction;
use App\Filament\Widgets\UpcomingInvoicesWidget;
use App\Models\Account;
use App\Models\Category;
use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-06 10:00:00');

    $this->household = Household::factory()->create();
    $this->user = User::factory()->withTwoFactor()->inHousehold($this->household)->create();
    $this->category = Category::factory()->expense()->create(['household_id' => $this->household->id]);
    $this->checking = Account::factory()->ownedBy($this->user)->create();
    // fecha dia 3, vence dia 10
    $this->card = app(CreateAccount::class)->execute($this->user, [
        'name' => 'Cartão', 'type' => 'credit_card', 'visibility' => 'private', 'currency' => 'BRL',
        'card_closing_day' => 3, 'card_due_day' => 10, 'card_limit' => 500000,
    ]);

    $this->buy = fn (int $amount, string $date) => app(CreateTransaction::class)->execute($this->user, [
        'account_id' => $this->card->id, 'category_id' => $this->category->id,
        'amount' => $amount, 'date' => $date, 'description' => 'Compra',
    ]);
});

function minorTotals(array $totals): array
{
    return array_map(fn ($m) => $m->getMinorAmount()->toInt(), $totals);
}

it('vencimento da fatura aparece em vencendo em 7 dias e depois em atrasados', function () {
    ($this->buy)(30000, '2026-09-20'); // fatura de outubro, vence 10/10
    $this->actingAs($this->user);

    expect(app(BillsSummary::class)->dueSoon()['count'])->toBe(1)
        ->and(minorTotals(app(BillsSummary::class)->dueSoon()['totals']))->toBe(['BRL' => -30000])
        ->and(minorTotals(app(BillsSummary::class)->forecastForMonth()['payable']))->toBe(['BRL' => -30000]);

    Carbon::setTestNow('2026-10-12');

    expect(app(BillsSummary::class)->overdue()['count'])->toBe(1)
        ->and(app(BillsSummary::class)->dueSoon()['count'])->toBe(0);
});

it('fatura paga sai do widget', function () {
    $invoice = ($this->buy)(30000, '2026-09-20')->invoice;
    app(PayInvoice::class)->execute($this->user, $invoice, $this->checking->id);
    $this->actingAs($this->user);

    expect(app(BillsSummary::class)->dueSoon()['count'])->toBe(0)
        ->and(app(BillsSummary::class)->upcomingInvoices())->toBeEmpty();
});

it('conta fixa no cartão conta só pela fatura, não em dobro', function () {
    app(CreateRecurrence::class)->execute($this->user, [
        'account_id' => $this->card->id, 'category_id' => $this->category->id, 'amount' => 5000,
        'description' => 'Streaming', 'frequency' => 'monthly', 'day_of_month' => 1, 'start_date' => '2026-10-01',
    ]); // previsto em 01/10 → fatura de outubro (vence 10/10)
    $this->actingAs($this->user);

    expect(app(BillsSummary::class)->dueSoon()['count'])->toBe(1)
        ->and(minorTotals(app(BillsSummary::class)->dueSoon()['totals']))->toBe(['BRL' => -5000])
        ->and(app(BillsSummary::class)->upcomingQuery()->count())->toBe(0);
});

it('lista as faturas no painel com o botão pagar', function () {
    $invoice = ($this->buy)(30000, '2026-09-20')->invoice;
    $this->actingAs($this->user);

    Livewire::test(UpcomingInvoicesWidget::class)
        ->assertCanSeeTableRecords([$invoice])
        ->assertSee('R$ 300,00')
        ->callTableAction('payInvoice', $invoice, ['from_account_id' => $this->checking->id, 'date' => '2026-10-06', 'amount' => '300,00'])
        ->assertHasNoTableActionErrors();

    expect($invoice->refresh()->isPaid())->toBeTrue();
});
