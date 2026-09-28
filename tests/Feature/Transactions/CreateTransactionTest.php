<?php

use App\Domain\Transactions\CreateTransaction;
use App\Domain\Transactions\UpdateTransaction;
use App\Models\Account;
use App\Models\Category;
use App\Models\Household;
use App\Models\User;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->household = Household::factory()->create();
    $this->user = User::factory()->inHousehold($this->household)->create();
    $this->partner = User::factory()->inHousehold($this->household)->create();
    $this->account = Account::factory()->ownedBy($this->user)->create();
    $this->expense = Category::factory()->expense()->create(['household_id' => $this->household->id]);
    $this->income = Category::factory()->income()->create(['household_id' => $this->household->id]);
});

function transactionData(array $overrides = []): array
{
    return [
        'account_id' => test()->account->id,
        'category_id' => test()->expense->id,
        'amount' => 12345,
        'date' => '2026-09-15',
        'description' => 'Mercado',
        ...$overrides,
    ];
}

it('grava despesa negativa e receita positiva pelo tipo da categoria', function () {
    $expense = app(CreateTransaction::class)->execute($this->user, transactionData());
    $income = app(CreateTransaction::class)->execute($this->user, transactionData([
        'category_id' => $this->income->id,
        'amount' => -500000,
    ]));

    expect($expense->amount->getMinorAmount()->toInt())->toBe(-12345)
        ->and($income->amount->getMinorAmount()->toInt())->toBe(500000);
});

it('usa o mês da data como competência quando não informada', function () {
    $transaction = app(CreateTransaction::class)->execute($this->user, transactionData());

    expect($transaction->competence_date->toDateString())->toBe('2026-09-01');
});

it('normaliza a competência informada para o dia 1', function () {
    $transaction = app(CreateTransaction::class)->execute($this->user, transactionData([
        'competence_date' => '2026-10-20',
    ]));

    expect($transaction->competence_date->toDateString())->toBe('2026-10-01');
});

it('preenche lar, moeda e pago por', function () {
    $usd = Account::factory()->ownedBy($this->user)->create(['currency' => 'USD']);

    $transaction = app(CreateTransaction::class)->execute($this->user, transactionData(['account_id' => $usd->id]));

    expect($transaction->household_id)->toBe($this->household->id)
        ->and($transaction->currency)->toBe('USD')
        ->and($transaction->paid_by)->toBe($this->user->id);
});

it('registra quem pagou quando é outro membro do lar', function () {
    $transaction = app(CreateTransaction::class)->execute($this->user, transactionData(['paid_by' => $this->partner->id]));

    expect($transaction->paid_by)->toBe($this->partner->id);
});

it('rejeita pago por de fora do lar', function () {
    $stranger = User::factory()->inHousehold(Household::factory()->create())->create();

    app(CreateTransaction::class)->execute($this->user, transactionData(['paid_by' => $stranger->id]));
})->throws(ValidationException::class, 'membro do lar');

it('não permite lançar em conta privada de outro usuário', function () {
    $partnerAccount = Account::factory()->ownedBy($this->partner)->private()->create();

    app(CreateTransaction::class)->execute($this->user, transactionData(['account_id' => $partnerAccount->id]));
})->throws(ValidationException::class, 'Conta não encontrada.');

it('não permite lançar em conta arquivada', function () {
    $archived = Account::factory()->ownedBy($this->user)->archived()->create();

    app(CreateTransaction::class)->execute($this->user, transactionData(['account_id' => $archived->id]));
})->throws(ValidationException::class, 'arquivada');

it('não aceita categoria de outro lar', function () {
    $foreign = Category::factory()->expense()->create();

    app(CreateTransaction::class)->execute($this->user, transactionData(['category_id' => $foreign->id]));
})->throws(ValidationException::class, 'Categoria não encontrada.');

it('não aceita valor zero', function () {
    app(CreateTransaction::class)->execute($this->user, transactionData(['amount' => 0]));
})->throws(ValidationException::class);

it('recalcula o sinal ao trocar a categoria na edição', function () {
    $transaction = app(CreateTransaction::class)->execute($this->user, transactionData());

    app(UpdateTransaction::class)->execute($this->user, $transaction, transactionData([
        'category_id' => $this->income->id,
    ]));

    expect($transaction->refresh()->amount->getMinorAmount()->toInt())->toBe(12345);
});

it('guarda tags sem repetição', function () {
    $transaction = app(CreateTransaction::class)->execute($this->user, transactionData([
        'tags' => ['viagem', ' viagem', 'praia'],
    ]));

    expect($transaction->refresh()->tags)->toBe(['viagem', 'praia']);
});
