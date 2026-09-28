<?php

use App\Domain\Accounts\AccountBalance;
use App\Domain\Transactions\CreateTransaction;
use App\Domain\Transactions\DeleteTransaction;
use App\Domain\Transactions\MarkAsPaid;
use App\Domain\Transactions\UpdateTransaction;
use App\Domain\Transfers\CreateTransfer;
use App\Domain\Transfers\TransferLabel;
use App\Domain\Transfers\UpdateTransfer;
use App\Enums\TransactionStatus;
use App\Filament\Resources\Transactions\Pages\ListTransactions;
use App\Models\Account;
use App\Models\Category;
use App\Models\Household;
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

    $this->personal = Account::factory()->ownedBy($this->eduardo)->private()->create(['name' => 'Nubank', 'initial_balance' => 100000]);
    $this->joint = Account::factory()->ownedBy($this->eduardo)->shared()->create(['name' => 'Conjunta', 'initial_balance' => 0]);
    $this->savings = Account::factory()->ownedBy($this->maria)->shared()->create(['name' => 'Poupança']);

    $this->transfer = fn (array $data = [], ?User $actor = null) => app(CreateTransfer::class)->execute($actor ?? $this->eduardo, [
        'from_account_id' => $this->personal->id,
        'to_account_id' => $this->joint->id,
        'amount' => 30000,
        'date' => '2026-09-15',
        ...$data,
    ]);
});

it('gera exatamente 2 lançamentos de sinais opostos ligados pelo transfer_id', function () {
    $legs = ($this->transfer)();

    $pair = Transaction::where('transfer_id', $legs->out->transfer_id)->get();

    expect($pair)->toHaveCount(2)
        ->and($pair->sum(fn (Transaction $t) => $t->amount->getMinorAmount()->toInt()))->toBe(0)
        ->and($legs->out->account_id)->toBe($this->personal->id)
        ->and($legs->out->amount->getMinorAmount()->toInt())->toBe(-30000)
        ->and($legs->in->account_id)->toBe($this->joint->id)
        ->and($legs->in->amount->getMinorAmount()->toInt())->toBe(30000)
        ->and($pair->pluck('category_id')->filter())->toBeEmpty()
        ->and(AccountBalance::of($this->personal)->getMinorAmount()->toInt())->toBe(70000)
        ->and(AccountBalance::of($this->joint)->getMinorAmount()->toInt())->toBe(30000);
});

it('editar uma perna altera o par', function () {
    $legs = ($this->transfer)();

    app(UpdateTransfer::class)->execute($this->eduardo, $legs->in, [
        'from_account_id' => $this->personal->id,
        'to_account_id' => $this->savings->id,
        'amount' => 45000,
        'date' => '2026-09-16',
        'description' => 'Reserva',
    ]);

    $pair = Transaction::where('transfer_id', $legs->out->transfer_id)->orderBy('amount')->get();

    expect($pair)->toHaveCount(2)
        ->and($pair[0]->account_id)->toBe($this->personal->id)
        ->and($pair[0]->amount->getMinorAmount()->toInt())->toBe(-45000)
        ->and($pair[1]->account_id)->toBe($this->savings->id)
        ->and($pair[1]->amount->getMinorAmount()->toInt())->toBe(45000)
        ->and($pair->pluck('description')->unique()->all())->toBe(['Reserva'])
        ->and($pair->map(fn ($t) => $t->date->toDateString())->unique()->all())->toBe(['2026-09-16']);
});

it('excluir uma perna exclui o par', function () {
    $legs = ($this->transfer)();

    app(DeleteTransaction::class)->execute($this->eduardo, $legs->in);

    expect(Transaction::where('transfer_id', $legs->out->transfer_id)->count())->toBe(0);
});

it('exclusão em massa exclui os pares inteiros', function () {
    $legs = ($this->transfer)();
    $other = ($this->transfer)(['amount' => 100]);
    $this->actingAs($this->eduardo);

    Livewire::test(ListTransactions::class)
        ->callTableBulkAction('deleteSelected', [$legs->out, $legs->in, $other->in])
        ->assertHasNoTableBulkActionErrors();

    expect(Transaction::count())->toBe(0);
});

it('lançamento comum não edita perna de transferência', function () {
    $legs = ($this->transfer)();

    app(UpdateTransaction::class)->execute($this->eduardo, $legs->out, ['description' => 'x']);
})->throws(ValidationException::class, 'formulário de transferência');

it('rejeita contas iguais e moedas diferentes', function () {
    $usd = Account::factory()->ownedBy($this->eduardo)->create(['currency' => 'USD']);

    expect(fn () => ($this->transfer)(['to_account_id' => $this->personal->id]))
        ->toThrow(ValidationException::class, 'diferente da de origem')
        ->and(fn () => ($this->transfer)(['to_account_id' => $usd->id]))
        ->toThrow(ValidationException::class, 'mesma moeda');
});

it('transferência prevista é paga nas duas pernas', function () {
    $legs = ($this->transfer)(['status' => 'scheduled', 'due_date' => '2026-09-25']);

    app(MarkAsPaid::class)->execute($this->eduardo, $legs->in, Carbon::parse('2026-09-21'));

    expect(Transaction::where('transfer_id', $legs->out->transfer_id)->get())
        ->each(fn ($t) => $t->status->toBe(TransactionStatus::Paid));
});

it('fica fora de receitas e despesas', function () {
    ($this->transfer)();
    $category = Category::factory()->expense()->create(['household_id' => $this->household->id]);
    $expense = app(CreateTransaction::class)->execute($this->eduardo, [
        'account_id' => $this->joint->id, 'category_id' => $category->id,
        'amount' => 1000, 'date' => '2026-09-15', 'description' => 'Mercado',
    ]);

    expect(Transaction::incomeAndExpense()->pluck('id')->all())->toBe([$expense->id]);
});

it('quem não vê a conta privada vê só a perna compartilhada, sem o nome da conta, e não altera', function () {
    $legs = ($this->transfer)();
    $this->actingAs($this->maria);

    expect(Transaction::pluck('id')->all())->toBe([$legs->in->id])
        ->and(TransferLabel::for($legs->in, $this->maria))->toBe('Transferência de conta pessoal de Eduardo')
        ->and(TransferLabel::for($legs->in, $this->eduardo))->toBe('Transferência de Nubank')
        ->and($this->maria->can('view', $legs->in))->toBeTrue()
        ->and($this->maria->can('update', $legs->in))->toBeFalse()
        ->and($this->maria->can('delete', $legs->in))->toBeFalse()
        ->and(fn () => app(DeleteTransaction::class)->execute($this->maria, $legs->in))
        ->toThrow(ValidationException::class, 'duas contas');

    Livewire::test(ListTransactions::class)
        ->assertCanSeeTableRecords([$legs->in])
        ->assertSee('Transferência de conta pessoal de Eduardo')
        ->assertDontSee('Nubank')
        ->assertTableActionHidden('editTransfer', $legs->in);

    expect(Transaction::where('transfer_id', $legs->in->transfer_id)->withoutGlobalScopes()->count())->toBe(2);
});

it('cria e edita transferência pelas telas', function () {
    $this->actingAs($this->eduardo);

    Livewire::test(ListTransactions::class)
        ->callAction('createTransfer', [
            'from_account_id' => $this->personal->id,
            'to_account_id' => $this->joint->id,
            'amount' => '500,00',
            'status' => 'paid',
            'date' => '2026-09-18',
            'description' => 'Aporte',
        ])
        ->assertHasNoActionErrors();

    $in = Transaction::where('amount', 50000)->sole();

    Livewire::test(ListTransactions::class)
        ->assertTableActionVisible('editTransfer', $in)
        ->callTableAction('editTransfer', $in, ['amount' => '600,00'])
        ->assertHasNoTableActionErrors();

    expect(Transaction::where('transfer_id', $in->transfer_id)->pluck('amount')
        ->map(fn ($m) => $m->getMinorAmount()->toInt())->sort()->values()->all())->toBe([-60000, 60000]);
});
