<?php

use App\Domain\Accounts\AccountBalance;
use App\Domain\Household\CreateHousehold;
use App\Domain\Investments\ManageOperations;
use App\Domain\Investments\SaveAsset;
use App\Domain\Reports\MonthlySummary;
use App\Domain\Transactions\DeleteTransaction;
use App\Domain\Transactions\UpdateTransaction;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Asset;
use App\Models\AssetOperation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');

    $this->household = app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $this->eduardo = User::where('email', 'eduardo@example.com')->sole();
    $this->maria = User::factory()->inHousehold($this->household)->create();
    $this->broker = Account::factory()->ownedBy($this->eduardo)->shared()->create(['type' => AccountType::Brokerage, 'name' => 'XP', 'initial_balance' => 500000]);
    $this->privateBroker = Account::factory()->ownedBy($this->eduardo)->private()->create(['type' => AccountType::Brokerage, 'name' => 'Rico']);

    $this->asset = app(SaveAsset::class)->execute($this->eduardo, ['account_id' => $this->broker->id, 'type' => 'stock', 'ticker' => 'petr4', 'name' => 'Petrobras PN']);
    $this->operations = app(ManageOperations::class);
    $this->buy = fn (string $date, string $quantity, string $price, int $fees = 0) => $this->operations->register($this->eduardo, $this->asset, [
        'type' => 'buy', 'date' => $date, 'quantity' => $quantity, 'unit_price' => $price, 'fees' => $fees,
    ]);
});

it('cadastra ativo com ticker em maiúsculas e só em corretora', function () {
    $checking = Account::factory()->ownedBy($this->eduardo)->create();

    expect($this->asset->ticker)->toBe('PETR4')
        ->and(fn () => app(SaveAsset::class)->execute($this->eduardo, ['account_id' => $checking->id, 'type' => 'stock', 'ticker' => 'VALE3', 'name' => 'Vale']))
        ->toThrow(ValidationException::class, 'corretora')
        ->and(fn () => app(SaveAsset::class)->execute($this->eduardo, ['account_id' => $this->broker->id, 'type' => 'stock', 'ticker' => 'PETR4', 'name' => 'X']))
        ->toThrow(ValidationException::class, 'já está cadastrado');
});

it('compra e venda geram lançamento na corretora, fora dos relatórios', function () {
    ($this->buy)('2026-09-10', '100', '38,50', 490);
    $sale = $this->operations->register($this->eduardo, $this->asset, [
        'type' => 'sell', 'date' => '2026-09-15', 'quantity' => '40', 'unit_price' => '40', 'fees' => 300,
    ]);

    $transactions = Transaction::where('account_id', $this->broker->id)->orderBy('date')->get();

    expect($transactions->map(fn ($t) => [$t->description, $t->amount->getMinorAmount()->toInt(), $t->category_id])->all())->toBe([
        ['Compra de 100 PETR4', -(385000 + 490), null],
        ['Venda de 40 PETR4', 160000 - 300, null],
    ])
        ->and(AccountBalance::of($this->broker)->getMinorAmount()->toInt())->toBe(500000 - 385490 + 159700);

    $this->actingAs($this->eduardo);
    expect(app(MonthlySummary::class)->totals(Carbon::parse('2026-09-01'))['expense']['total'])->toBe(0)
        ->and(Transaction::incomeAndExpense()->count())->toBe(0);
});

it('desdobramento não gera lançamento', function () {
    ($this->buy)('2026-09-10', '100', '10');
    $this->operations->register($this->eduardo, $this->asset, ['type' => 'split', 'date' => '2026-09-12', 'factor' => '2']);

    expect(Transaction::count())->toBe(1);
});

it('recusa venda acima da posição, inclusive ao editar ou excluir operação antiga', function () {
    $buy = ($this->buy)('2026-09-10', '100', '10');
    $this->operations->register($this->eduardo, $this->asset, ['type' => 'sell', 'date' => '2026-09-15', 'quantity' => '80', 'unit_price' => '12']);

    expect(fn () => $this->operations->register($this->eduardo, $this->asset, ['type' => 'sell', 'date' => '2026-09-16', 'quantity' => '30', 'unit_price' => '12']))
        ->toThrow(ValidationException::class, 'maior que a posição')
        ->and(fn () => $this->operations->update($this->eduardo, $buy, ['type' => 'buy', 'date' => '2026-09-10', 'quantity' => '50', 'unit_price' => '10']))
        ->toThrow(ValidationException::class, 'maior que a posição')
        ->and(fn () => $this->operations->delete($this->eduardo, $buy))
        ->toThrow(ValidationException::class, 'maior que a posição')
        ->and(AssetOperation::count())->toBe(2);
});

it('editar a operação atualiza o lançamento; excluir apaga os dois', function () {
    $buy = ($this->buy)('2026-09-10', '100', '10');
    $this->operations->update($this->eduardo, $buy, ['type' => 'buy', 'date' => '2026-09-11', 'quantity' => '120', 'unit_price' => '10', 'fees' => 100]);

    $transaction = Transaction::where('asset_operation_id', $buy->id)->sole();
    expect($transaction->amount->getMinorAmount()->toInt())->toBe(-120100)
        ->and($transaction->date->toDateString())->toBe('2026-09-11')
        ->and($transaction->description)->toBe('Compra de 120 PETR4');

    $this->operations->delete($this->eduardo, $buy);
    expect(AssetOperation::count())->toBe(0)->and(Transaction::count())->toBe(0);
});

it('o lançamento gerado não é editado como lançamento comum; excluir por ele exclui a operação', function () {
    ($this->buy)('2026-09-10', '100', '10');
    $transaction = Transaction::sole();

    expect(fn () => app(UpdateTransaction::class)->execute($this->eduardo, $transaction, ['description' => 'x']))
        ->toThrow(ValidationException::class, 'Carteira');

    app(DeleteTransaction::class)->execute($this->eduardo, $transaction);

    expect(AssetOperation::count())->toBe(0);
});

it('usuário A não vê nem opera ativos da corretora privada de B', function () {
    $private = app(SaveAsset::class)->execute($this->eduardo, ['account_id' => $this->privateBroker->id, 'type' => 'fii', 'ticker' => 'HGLG11', 'name' => 'CSHG Log']);

    expect(fn () => $this->operations->register($this->maria, $private, ['type' => 'buy', 'date' => '2026-09-10', 'quantity' => '1', 'unit_price' => '160']))
        ->toThrow(ValidationException::class, 'Sem acesso');

    $this->actingAs($this->maria);
    expect(Asset::pluck('ticker')->all())->toBe(['PETR4']);
});
