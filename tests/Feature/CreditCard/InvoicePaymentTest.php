<?php

use App\Domain\Accounts\AccountBalance;
use App\Domain\Accounts\CreateAccount;
use App\Domain\CreditCard\CreateInstallmentPurchase;
use App\Domain\CreditCard\InvoiceTotals;
use App\Domain\CreditCard\PayInvoice;
use App\Domain\Transactions\CreateTransaction;
use App\Domain\Transactions\DeleteTransaction;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Invoices\RelationManagers\TransactionsRelationManager;
use App\Models\Account;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\Household;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');

    $this->household = Household::factory()->create();
    $this->eduardo = User::factory()->withTwoFactor()->inHousehold($this->household)->create(['name' => 'Eduardo']);
    $this->maria = User::factory()->withTwoFactor()->inHousehold($this->household)->create(['name' => 'Maria']);
    $this->category = Category::factory()->expense()->create(['household_id' => $this->household->id]);
    $this->checking = Account::factory()->ownedBy($this->eduardo)->shared()->create(['initial_balance' => 1000000]);

    $this->newCard = fn (string $visibility, User $owner) => app(CreateAccount::class)->execute($owner, [
        'name' => "Cartão {$visibility}", 'type' => 'credit_card', 'visibility' => $visibility, 'currency' => 'BRL',
        'card_closing_day' => 3, 'card_due_day' => 10, 'card_limit' => 500000,
    ]);
    $this->cardAccount = ($this->newCard)('shared', $this->eduardo);
    $this->card = CreditCard::where('account_id', $this->cardAccount->id)->sole();

    $this->purchase = fn (int $amount, string $date = '2026-09-25', ?Account $account = null) => app(CreateTransaction::class)->execute($this->eduardo, [
        'account_id' => ($account ?? $this->cardAccount)->id, 'category_id' => $this->category->id,
        'amount' => $amount, 'date' => $date, 'description' => 'Compra',
    ]);
});

it('soma das parcelas futuras reduz o limite disponível', function () {
    expect(InvoiceTotals::availableLimit($this->card)->getMinorAmount()->toInt())->toBe(500000);

    ($this->purchase)(20000);
    app(CreateInstallmentPurchase::class)->execute($this->eduardo, [
        'account_id' => $this->cardAccount->id, 'category_id' => $this->category->id,
        'amount' => 120000, 'installments' => 12, 'date' => '2026-09-25', 'description' => 'Notebook',
    ]);

    expect(InvoiceTotals::availableLimit($this->card)->getMinorAmount()->toInt())->toBe(500000 - 20000 - 120000);
});

it('pagar a fatura gera a transferência, marca como paga e libera o limite', function () {
    $purchase = ($this->purchase)(30000);
    ($this->purchase)(5000, '2026-10-05'); // fatura seguinte
    $invoice = $purchase->invoice;

    app(PayInvoice::class)->execute($this->eduardo, $invoice, $this->checking->id, Carbon::parse('2026-10-08'));

    $transfer = Transaction::where('transfer_id', $invoice->refresh()->payment_transfer_id)->orderBy('amount')->get();

    expect($invoice->isPaid())->toBeTrue()
        ->and($transfer->map(fn ($t) => [$t->account_id, $t->amount->getMinorAmount()->toInt()])->all())
        ->toBe([[$this->checking->id, -30000], [$this->cardAccount->id, 30000]])
        ->and($transfer->pluck('invoice_id')->filter())->toBeEmpty()
        ->and(InvoiceTotals::amountDue($invoice)->getMinorAmount()->toInt())->toBe(30000)
        ->and(InvoiceTotals::availableLimit($this->card)->getMinorAmount()->toInt())->toBe(500000 - 5000)
        ->and(AccountBalance::of($this->cardAccount)->getMinorAmount()->toInt())->toBe(-5000);
});

it('compra depois do pagamento vai para a fatura seguinte e não paga duas vezes', function () {
    $invoice = ($this->purchase)(30000)->invoice;
    app(PayInvoice::class)->execute($this->eduardo, $invoice, $this->checking->id);

    expect(($this->purchase)(1000, '2026-09-28')->invoice->reference_month->format('Y-m'))->toBe('2026-11')
        ->and(fn () => app(PayInvoice::class)->execute($this->eduardo, $invoice->refresh(), $this->checking->id))
        ->toThrow(ValidationException::class, 'já está paga');
});

it('excluir o pagamento reabre a fatura', function () {
    $invoice = ($this->purchase)(30000)->invoice;
    app(PayInvoice::class)->execute($this->eduardo, $invoice, $this->checking->id);
    $leg = Transaction::where('transfer_id', $invoice->refresh()->payment_transfer_id)->first();

    app(DeleteTransaction::class)->execute($this->eduardo, $leg);

    expect($invoice->refresh()->isPaid())->toBeFalse()
        ->and($invoice->payment_transfer_id)->toBeNull();
});

it('situação: aberta antes do fechamento, fechada depois, atrasada após o vencimento', function () {
    $invoice = ($this->purchase)(1000)->invoice; // fecha 03/10, vence 10/10

    expect($invoice->status()->value)->toBe('open');
    Carbon::setTestNow('2026-10-03');
    expect($invoice->status()->value)->toBe('closed')->and($invoice->isOverdue())->toBeFalse();
    Carbon::setTestNow('2026-10-11');
    expect($invoice->isOverdue())->toBeTrue();
});

it('paga pela tela da fatura e mostra itens, limite e próximas faturas', function () {
    $invoice = ($this->purchase)(30000)->invoice;
    app(CreateInstallmentPurchase::class)->execute($this->eduardo, [
        'account_id' => $this->cardAccount->id, 'category_id' => $this->category->id,
        'amount' => 60000, 'installments' => 3, 'date' => '2026-09-26', 'description' => 'Cadeira',
    ]);
    $this->actingAs($this->maria);

    $this->get(route('filament.app.resources.invoices.view', $invoice))
        ->assertSuccessful()
        ->assertSee('R$ 500,00')          // total: 300 + 200 da 1ª parcela
        ->assertSee('R$ 4.100,00')        // disponível: 5.000 − 300 − 600
        ->assertSee('Novembro/2026');

    Livewire::test(TransactionsRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => ViewInvoice::class])
        ->assertCanSeeTableRecords(Transaction::where('invoice_id', $invoice->id)->get())
        ->assertSee('Cadeira (1/3)')
        ->assertDontSee('Cadeira (2/3)');

    Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
        ->callAction('payInvoice', ['from_account_id' => $this->checking->id, 'date' => '2026-10-05', 'amount' => '500,00'])
        ->assertHasNoActionErrors();

    expect($invoice->refresh()->isPaid())->toBeTrue();
});

it('outro usuário não vê cartão pessoal, suas faturas nem compras', function () {
    $personal = ($this->newCard)('private', $this->eduardo);
    $purchase = ($this->purchase)(1000, '2026-09-25', $personal);
    $shared = ($this->purchase)(2000);
    $privateInvoice = $purchase->invoice;
    $sharedInvoice = $shared->invoice;
    $this->actingAs($this->maria);

    expect(Invoice::pluck('id')->all())->toBe([$shared->invoice_id])
        ->and(Transaction::find($purchase->id))->toBeNull()
        ->and($this->maria->can('pay', $privateInvoice))->toBeFalse()
        ->and($this->maria->can('pay', $sharedInvoice))->toBeTrue();

    Livewire::test(ListInvoices::class)
        ->assertCanSeeTableRecords([$sharedInvoice])
        ->assertCanNotSeeTableRecords([$privateInvoice]);

    $this->get(route('filament.app.resources.invoices.view', $privateInvoice))->assertNotFound();
});
