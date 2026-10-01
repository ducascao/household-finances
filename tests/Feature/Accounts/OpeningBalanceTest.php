<?php

use App\Domain\Accounts\AccountBalance;
use App\Domain\Accounts\SetOpeningBalance;
use App\Domain\Accounts\UpdateAccount;
use App\Domain\Household\CreateHousehold;
use App\Domain\Investments\ManageOperations;
use App\Domain\Investments\ManageValuations;
use App\Domain\Investments\SaveAsset;
use App\Domain\NetWorth\NetWorthCalculator;
use App\Domain\NetWorth\NetWorthScope;
use App\Domain\Transactions\CreateTransaction;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Widgets\AccountBalancesWidget;
use App\Models\Account;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-09-30 10:00:00');

    $this->household = app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $this->user = User::where('email', 'eduardo@example.com')->sole();
    $this->user->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
    $this->maria = User::factory()->withTwoFactor()->inHousehold($this->household)->create();
    $this->account = Account::factory()->ownedBy($this->user)->private()->create(['name' => 'PicPay', 'initial_balance' => 0]);
    $expense = Category::where('household_id', $this->household->id)->where('name', 'Mercado')->sole();

    // Histórico antigo: aporte de R$ 10.000 num cofrinho (sai da conta) e uma compra de R$ 100
    $this->asset = app(SaveAsset::class)->execute($this->user, ['account_id' => $this->account->id, 'type' => 'fixed_income', 'name' => 'Cofrinho']);
    app(ManageOperations::class)->register($this->user, $this->asset, ['type' => 'contribution', 'date' => '2026-08-17', 'amount' => 1000000]);
    app(ManageValuations::class)->save($this->user, $this->asset, Carbon::parse('2026-09-29'), 1015000);

    $add = fn (array $data) => app(CreateTransaction::class)->execute($this->user, [
        'account_id' => $this->account->id, 'category_id' => $expense->id, 'description' => 'Mercado', ...$data,
    ]);
    $add(['amount' => 10000, 'date' => '2026-09-10']);
    $add(['amount' => 5000, 'date' => '2026-09-20']);                                       // depois da data: conta
    $add(['amount' => 20000, 'status' => 'scheduled', 'due_date' => '2026-09-05']);          // previsto atrasado: conta no projetado

    $this->balances = function (): array {
        $account = Account::withoutGlobalScopes()->findOrFail($this->account->id);
        $fromQuery = AccountBalance::addToQuery(Account::withoutGlobalScopes()->whereKey($account->id))->sole();

        return [
            AccountBalance::of($account)->getMinorAmount()->toInt(),
            AccountBalance::projectedOf($account)->getMinorAmount()->toInt(),
            AccountBalance::of($fromQuery)->getMinorAmount()->toInt(),
            AccountBalance::projectedOf($fromQuery)->getMinorAmount()->toInt(),
            AccountBalance::at($account, today())->getMinorAmount()->toInt(),
        ];
    };
});

it('sem data do saldo inicial, todo o histórico conta (como antes)', function () {
    // 0 − 10.000 − 100 − 50 = −10.150; projetado −10.350
    expect(($this->balances)())->toBe([-1015000, -1035000, -1015000, -1035000, -1035000]);
});

it('com a data, os pagos até ela ficam fora do saldo; previstos continuam no projetado', function () {
    app(SetOpeningBalance::class)->execute($this->user, $this->account, 100000, Carbon::parse('2026-09-15'));

    // 1.000 − 50 (20/09) = 950; projetado 950 − 200 (previsto atrasado) = 750
    expect(($this->balances)())->toBe([95000, 75000, 95000, 75000, 75000]);

    // A carteira não muda: o cofrinho continua valendo o saldo informado
    expect(app(NetWorthCalculator::class)->at(NetWorthScope::person($this->user), today())->investments())->toBe(1015000);
});

it('o saldo informado vale no fim do dia: lançamento pago no próprio dia não entra', function () {
    app(SetOpeningBalance::class)->execute($this->user, $this->account, 100000, Carbon::parse('2026-09-20'));

    expect(AccountBalance::of($this->account->refresh())->getMinorAmount()->toInt())->toBe(100000);
});

it('patrimônio: antes da data a conta fica de fora; a partir dela, saldo inicial + pagos depois', function () {
    app(SetOpeningBalance::class)->execute($this->user, $this->account, 100000, Carbon::parse('2026-09-15'));
    $at = fn (string $date) => app(NetWorthCalculator::class)->at(NetWorthScope::person($this->user), Carbon::parse($date))->accountItems;

    expect($at('2026-09-14'))->toBe([])
        ->and($at('2026-09-15'))->toBe(['PicPay' => 100000])
        ->and($at('2026-09-30'))->toBe(['PicPay' => 95000]);
});

it('pelo formulário da conta e pela ação "Ajustar saldo" na lista; painel mostra o saldo novo', function () {
    $this->actingAs($this->user);

    app(UpdateAccount::class)->execute($this->user, $this->account, [
        'name' => 'PicPay', 'type' => 'checking', 'visibility' => 'private', 'currency' => 'BRL', 'initial_balance' => 50000, 'balance_date' => '2026-09-15',
    ]);
    expect(AccountBalance::of($this->account->refresh())->getMinorAmount()->toInt())->toBe(45000);

    Livewire::test(ListAccounts::class)
        ->callTableAction('setOpeningBalance', $this->account, ['balance' => '1.234,56', 'date' => '2026-09-30'])
        ->assertHasNoTableActionErrors()
        ->assertSee('R$ 1.234,56')
        ->assertSee('desde 30/09/2026');

    expect($this->account->refresh()->balance_date?->toDateString())->toBe('2026-09-30')
        ->and(AccountBalance::of($this->account)->getMinorAmount()->toInt())->toBe(123456);

    Livewire::test(AccountBalancesWidget::class)->assertSee('R$ 1.234,56');
});

it('não aceita data futura e o outro usuário não ajusta a conta pessoal alheia', function () {
    expect(fn () => app(SetOpeningBalance::class)->execute($this->user, $this->account, 1, Carbon::parse('2026-10-01')))->toThrow(ValidationException::class)
        ->and(fn () => app(SetOpeningBalance::class)->execute($this->maria, $this->account, 1, today()))->toThrow(ValidationException::class)
        ->and(fn () => app(UpdateAccount::class)->execute($this->user, $this->account, [
            'name' => 'PicPay', 'type' => 'checking', 'visibility' => 'private', 'currency' => 'BRL', 'initial_balance' => 0, 'balance_date' => '2026-10-05',
        ]))->toThrow(ValidationException::class);

    $this->actingAs($this->maria);
    Livewire::test(ListAccounts::class)->assertCanNotSeeTableRecords([$this->account]);
});
