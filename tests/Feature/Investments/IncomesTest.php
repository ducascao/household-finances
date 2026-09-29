<?php

use App\Domain\Household\CreateHousehold;
use App\Domain\Investments\ManageIncomes;
use App\Domain\Investments\SaveAsset;
use App\Domain\Reports\MonthlySummary;
use App\Domain\Transactions\DeleteTransaction;
use App\Domain\Transactions\UpdateTransaction;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AssetIncome;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->household = app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $this->user = User::where('email', 'eduardo@example.com')->sole();
    $this->maria = User::factory()->inHousehold($this->household)->create();
    $this->broker = Account::factory()->ownedBy($this->user)->private()->create(['type' => AccountType::Brokerage]);
    $this->asset = app(SaveAsset::class)->execute($this->user, ['account_id' => $this->broker->id, 'type' => 'stock', 'ticker' => 'BBAS3', 'name' => 'BB']);
    $this->incomes = app(ManageIncomes::class);
});

it('cria as subcategorias de proventos em Rendimentos', function () {
    expect(Category::where('household_id', $this->household->id)->whereHas('parent', fn ($q) => $q->where('name', 'Rendimentos'))->pluck('name')->sort()->values()->all())
        ->toBe(['Dividendos', 'JCP', 'Rendimentos de FII']);
});

it('JCP gera receita pelo líquido na corretora, na subcategoria certa, e conta no resumo do mês', function () {
    $income = $this->incomes->register($this->user, $this->asset, [
        'type' => 'jcp', 'date' => '2026-09-15', 'gross_amount' => 10000, 'withheld_tax' => 1500,
    ]);

    $transaction = Transaction::where('asset_income_id', $income->id)->with('category')->sole();

    expect($transaction->amount->getMinorAmount()->toInt())->toBe(8500)
        ->and($transaction->account_id)->toBe($this->broker->id)
        ->and($transaction->category->name)->toBe('JCP')
        ->and($transaction->description)->toBe('JCP BBAS3')
        ->and($transaction->notes)->toBe('Bruto 100,00, IR retido 15,00');

    $this->actingAs($this->user);
    expect(app(MonthlySummary::class)->totals(Carbon::parse('2026-09-01'))['income']['total'])->toBe(8500);
});

it('editar e excluir mantêm o lançamento em sincronia', function () {
    $income = $this->incomes->register($this->user, $this->asset, ['type' => 'dividend', 'date' => '2026-09-15', 'gross_amount' => 5000]);

    $this->incomes->update($this->user, $income, ['type' => 'dividend', 'date' => '2026-09-20', 'gross_amount' => 6000]);
    $transaction = Transaction::where('asset_income_id', $income->id)->sole();

    expect($transaction->amount->getMinorAmount()->toInt())->toBe(6000)
        ->and($transaction->date->toDateString())->toBe('2026-09-20')
        ->and(fn () => app(UpdateTransaction::class)->execute($this->user, $transaction, ['description' => 'x']))->toThrow(ValidationException::class, 'tela do ativo');

    app(DeleteTransaction::class)->execute($this->user, $transaction);

    expect(AssetIncome::count())->toBe(0)->and(Transaction::count())->toBe(0);
});

it('valida IR menor que o bruto e acesso à corretora', function () {
    expect(fn () => $this->incomes->register($this->user, $this->asset, ['type' => 'jcp', 'date' => '2026-09-15', 'gross_amount' => 100, 'withheld_tax' => 100]))
        ->toThrow(ValidationException::class, 'menor que o valor bruto')
        ->and(fn () => $this->incomes->register($this->maria, $this->asset, ['type' => 'jcp', 'date' => '2026-09-15', 'gross_amount' => 100]))
        ->toThrow(ValidationException::class, 'Sem acesso');
});
