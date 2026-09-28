<?php

use App\Domain\Accounts\CreateAccount;
use App\Domain\Recurrences\CreateRecurrence;
use App\Domain\Transactions\CreateTransaction;
use App\Domain\Transactions\UpdateTransaction;
use App\Filament\Resources\Accounts\Pages\CreateAccount as CreateAccountPage;
use App\Models\Account;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\Household;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');

    $this->household = Household::factory()->create();
    $this->user = User::factory()->withTwoFactor()->inHousehold($this->household)->create();
    $this->category = Category::factory()->expense()->create(['household_id' => $this->household->id]);

    $this->cardAccount = app(CreateAccount::class)->execute($this->user, [
        'name' => 'Nubank Cartão', 'type' => 'credit_card', 'visibility' => 'private', 'currency' => 'BRL',
        'initial_balance' => 0, 'card_closing_day' => 3, 'card_due_day' => 10, 'card_limit' => 500000,
    ]);

    $this->purchase = fn (string $date, array $data = []) => app(CreateTransaction::class)->execute($this->user, [
        'account_id' => $this->cardAccount->id, 'category_id' => $this->category->id,
        'amount' => 10000, 'date' => $date, 'description' => 'Compra', ...$data,
    ]);
});

it('cria o cartão junto com a conta', function () {
    expect(CreditCard::where('account_id', $this->cardAccount->id)->sole())
        ->closing_day->toBe(3)
        ->due_day->toBe(10)
        ->and(CreditCard::where('account_id', $this->cardAccount->id)->sole()->limit->getMinorAmount()->toInt())->toBe(500000);
});

it('exige os dias do cartão quando a conta é cartão', function () {
    app(CreateAccount::class)->execute($this->user, [
        'name' => 'Sem dias', 'type' => 'credit_card', 'visibility' => 'private', 'currency' => 'BRL',
    ]);
})->throws(ValidationException::class);

it('compra no cartão vai para a fatura com competência do mês da fatura', function () {
    $before = ($this->purchase)('2026-10-02');
    $onClosing = ($this->purchase)('2026-10-03');

    expect($before->invoice->reference_month->format('Y-m'))->toBe('2026-10')
        ->and($before->competence_date->toDateString())->toBe('2026-10-01')
        ->and($onClosing->invoice->reference_month->format('Y-m'))->toBe('2026-11')
        ->and($onClosing->competence_date->toDateString())->toBe('2026-11-01')
        ->and(Invoice::count())->toBe(2);
});

it('lançamento em conta que não é cartão não tem fatura', function () {
    $checking = Account::factory()->ownedBy($this->user)->create();

    $transaction = app(CreateTransaction::class)->execute($this->user, [
        'account_id' => $checking->id, 'category_id' => $this->category->id,
        'amount' => 100, 'date' => '2026-09-10', 'description' => 'x',
    ]);

    expect($transaction->invoice_id)->toBeNull();
});

it('compra que cairia em fatura paga vai para a seguinte', function () {
    $first = ($this->purchase)('2026-09-25');
    $first->invoice->update(['paid_at' => now()]);

    $late = ($this->purchase)('2026-09-26');

    expect($late->invoice->reference_month->format('Y-m'))->toBe('2026-11');
});

it('mudar a data move a compra de fatura', function () {
    $purchase = ($this->purchase)('2026-09-25');

    app(UpdateTransaction::class)->execute($this->user, $purchase, [
        'account_id' => $this->cardAccount->id, 'category_id' => $this->category->id,
        'amount' => 10000, 'date' => '2026-10-05', 'description' => 'Compra',
    ]);

    expect($purchase->refresh()->invoice->reference_month->format('Y-m'))->toBe('2026-11');
});

it('não deixa alterar valor de compra em fatura paga', function () {
    $purchase = ($this->purchase)('2026-09-25');
    $purchase->invoice->update(['paid_at' => now()]);

    app(UpdateTransaction::class)->execute($this->user, $purchase->refresh(), [
        'account_id' => $this->cardAccount->id, 'category_id' => $this->category->id,
        'amount' => 99999, 'date' => '2026-09-25', 'description' => 'Compra',
    ]);
})->throws(ValidationException::class, 'fatura paga');

it('conta fixa no cartão gera previstos já nas faturas', function () {
    $recurrence = app(CreateRecurrence::class)->execute($this->user, [
        'account_id' => $this->cardAccount->id, 'category_id' => $this->category->id, 'amount' => 5590,
        'description' => 'Streaming', 'frequency' => 'monthly', 'day_of_month' => 3, 'start_date' => '2026-10-01',
    ]);

    expect($recurrence->transactions()->with('invoice')->get()->map(fn ($t) => $t->invoice->reference_month->format('Y-m'))->all())
        ->toBe(['2026-11', '2026-12']);
});

it('cria cartão pelo formulário de conta', function () {
    $this->actingAs($this->user);

    Livewire::test(CreateAccountPage::class)
        ->fillForm(['name' => 'Inter Cartão', 'type' => 'credit_card', 'visibility' => 'shared', 'currency' => 'BRL', 'initial_balance' => '0,00'])
        ->fillForm(['card_closing_day' => 25, 'card_due_day' => 5, 'card_limit' => '8.000,00'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Account::where('name', 'Inter Cartão')->sole()->creditCard)
        ->closing_day->toBe(25)
        ->and(Account::where('name', 'Inter Cartão')->sole()->creditCard->limit->getMinorAmount()->toInt())->toBe(800000);
});
