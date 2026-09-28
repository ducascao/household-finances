<?php

use App\Domain\Accounts\CreateAccount;
use App\Domain\CreditCard\CreateInstallmentPurchase;
use App\Domain\CreditCard\UpdateInstallmentPurchase;
use App\Domain\Transactions\CreateTransaction;
use App\Domain\Transactions\DeleteTransaction;
use App\Domain\Transactions\UpdateTransaction;
use App\Filament\Resources\Transactions\Pages\ListTransactions;
use App\Models\Account;
use App\Models\Category;
use App\Models\Household;
use App\Models\InstallmentGroup;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');

    $this->household = Household::factory()->create();
    $this->user = User::factory()->withTwoFactor()->inHousehold($this->household)->create();
    $this->category = Category::factory()->expense()->create(['household_id' => $this->household->id, 'name' => 'Casa']);
    $this->income = Category::factory()->income()->create(['household_id' => $this->household->id]);
    $this->card = app(CreateAccount::class)->execute($this->user, [
        'name' => 'Cartão', 'type' => 'credit_card', 'visibility' => 'private', 'currency' => 'BRL',
        'card_closing_day' => 3, 'card_due_day' => 10, 'card_limit' => 500000,
    ]);

    $this->buy = fn (int $total, int $installments, string $date = '2026-09-25') => app(CreateInstallmentPurchase::class)->execute($this->user, [
        'account_id' => $this->card->id, 'category_id' => $this->category->id,
        'amount' => $total, 'installments' => $installments, 'date' => $date, 'description' => 'Geladeira',
    ]);

    $this->installments = fn (InstallmentGroup $group) => Transaction::where('installment_group_id', $group->id)
        ->with('invoice')->orderBy('installment_number')->get();
});

it('1000,00 em 3x gera 333,33 + 333,33 + 333,34', function () {
    $group = ($this->buy)(100000, 3);

    expect(($this->installments)($group)->map(fn ($t) => $t->amount->getMinorAmount()->toInt())->all())
        ->toBe([-33333, -33333, -33334]);
});

it('cada parcela cai numa fatura seguinte, com k/N na descrição', function () {
    $group = ($this->buy)(100000, 3);

    expect(($this->installments)($group)->map(fn ($t) => [
        $t->description, $t->invoice->reference_month->format('Y-m'), $t->competence_date->toDateString(),
    ])->all())->toBe([
        ['Geladeira (1/3)', '2026-10', '2026-10-01'],
        ['Geladeira (2/3)', '2026-11', '2026-11-01'],
        ['Geladeira (3/3)', '2026-12', '2026-12-01'],
    ]);
});

it('soma das parcelas é o total, para vários valores', function (int $total, int $count) {
    $group = ($this->buy)($total, $count);
    $amounts = ($this->installments)($group)->map(fn ($t) => $t->amount->getMinorAmount()->toInt());

    expect($amounts->sum())->toBe(-$total)
        ->and($amounts)->toHaveCount($count);
})->with([[100, 3], [99999, 7], [123456, 12], [200, 2]]);

it('só em cartão e com valor suficiente', function () {
    $checking = Account::factory()->ownedBy($this->user)->create();

    expect(fn () => app(CreateInstallmentPurchase::class)->execute($this->user, [
        'account_id' => $checking->id, 'category_id' => $this->category->id,
        'amount' => 1000, 'installments' => 2, 'date' => '2026-09-25', 'description' => 'x',
    ]))->toThrow(ValidationException::class, 'cartão de crédito')
        ->and(fn () => ($this->buy)(2, 3))->toThrow(ValidationException::class, 'número de parcelas');
});

it('excluir uma parcela exclui a compra inteira', function () {
    $group = ($this->buy)(100000, 3);

    app(DeleteTransaction::class)->execute($this->user, ($this->installments)($group)[1]);

    expect(Transaction::count())->toBe(0)->and(InstallmentGroup::count())->toBe(0);
});

it('não exclui compra com parcela em fatura paga', function () {
    $group = ($this->buy)(100000, 3);
    ($this->installments)($group)[0]->invoice->update(['paid_at' => now()]);

    app(DeleteTransaction::class)->execute($this->user, ($this->installments)($group)[2]);
})->throws(ValidationException::class, 'faturas já pagas');

it('edita descrição e categoria de todas as parcelas, mas não uma parcela isolada', function () {
    $group = ($this->buy)(100000, 3);
    $other = Category::factory()->expense()->create(['household_id' => $this->household->id]);

    app(UpdateInstallmentPurchase::class)->execute($this->user, $group, ['description' => 'Fogão', 'category_id' => $other->id]);

    expect(($this->installments)($group)->pluck('description')->all())->toBe(['Fogão (1/3)', 'Fogão (2/3)', 'Fogão (3/3)'])
        ->and(($this->installments)($group)->pluck('category_id')->unique()->all())->toBe([$other->id])
        ->and(fn () => app(UpdateTransaction::class)->execute($this->user, ($this->installments)($group)[0], ['description' => 'x']))
        ->toThrow(ValidationException::class, 'compra parcelada');
});

it('estorno entra positivo e abate da fatura', function () {
    $purchase = app(CreateTransaction::class)->execute($this->user, [
        'account_id' => $this->card->id, 'category_id' => $this->category->id,
        'amount' => 30000, 'date' => '2026-09-25', 'description' => 'Tênis',
    ]);
    $refund = app(CreateTransaction::class)->execute($this->user, [
        'account_id' => $this->card->id, 'category_id' => $this->category->id,
        'amount' => 30000, 'date' => '2026-09-28', 'description' => 'Estorno tênis', 'is_refund' => true,
    ]);

    expect($refund->amount->getMinorAmount()->toInt())->toBe(30000)
        ->and($refund->invoice_id)->toBe($purchase->invoice_id)
        ->and((int) Transaction::where('invoice_id', $purchase->invoice_id)->sum('amount'))->toBe(0)
        ->and(fn () => app(CreateTransaction::class)->execute($this->user, [
            'account_id' => $this->card->id, 'category_id' => $this->income->id,
            'amount' => 100, 'date' => '2026-09-28', 'description' => 'x', 'is_refund' => true,
        ]))->toThrow(ValidationException::class, 'Estorno');
});

it('lança compra parcelada pela tela', function () {
    $this->actingAs($this->user);

    Livewire::test(ListTransactions::class)
        ->callAction('createInstallments', [
            'account_id' => $this->card->id, 'category_id' => $this->category->id,
            'amount' => '1.000,00', 'installments' => 3, 'date' => '2026-09-25', 'description' => 'TV',
        ])
        ->assertHasNoActionErrors();

    $group = InstallmentGroup::sole();

    expect(Transaction::where('installment_group_id', $group->id)->count())->toBe(3);

    Livewire::test(ListTransactions::class)
        ->assertTableActionVisible('editInstallments', ($this->installments)($group)[0])
        ->assertTableActionHidden('edit', ($this->installments)($group)[0]);
});
