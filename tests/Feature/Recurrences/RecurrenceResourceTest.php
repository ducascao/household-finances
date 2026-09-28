<?php

use App\Domain\Recurrences\CreateRecurrence;
use App\Filament\Resources\Recurrences\Pages\CreateRecurrence as CreateRecurrencePage;
use App\Filament\Resources\Recurrences\Pages\EditRecurrence;
use App\Filament\Resources\Recurrences\Pages\ListRecurrences;
use App\Filament\Resources\Transactions\Pages\ListTransactions;
use App\Models\Account;
use App\Models\Category;
use App\Models\Household;
use App\Models\Recurrence;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');

    $this->household = Household::factory()->create();
    $this->userA = User::factory()->withTwoFactor()->inHousehold($this->household)->create();
    $this->userB = User::factory()->withTwoFactor()->inHousehold($this->household)->create();
    $this->category = Category::factory()->expense()->create(['household_id' => $this->household->id]);

    $this->privateOfB = Account::factory()->ownedBy($this->userB)->private()->create();
    $this->shared = Account::factory()->ownedBy($this->userB)->shared()->create();

    $make = fn (Account $account, string $description) => app(CreateRecurrence::class)->execute($this->userB, [
        'account_id' => $account->id, 'category_id' => $this->category->id, 'amount' => 10000,
        'description' => $description, 'frequency' => 'monthly', 'day_of_month' => 10, 'start_date' => '2026-10-01',
    ]);

    $this->recurrenceOfB = $make($this->privateOfB, 'Academia do B');
    $this->sharedRecurrence = $make($this->shared, 'Internet');
});

it('usuário A não vê recorrências da conta privada de B', function () {
    $this->actingAs($this->userA);

    expect(Recurrence::pluck('id')->all())->toBe([$this->sharedRecurrence->id])
        ->and($this->userA->can('update', $this->recurrenceOfB))->toBeFalse();

    Livewire::test(ListRecurrences::class)
        ->assertCanSeeTableRecords([$this->sharedRecurrence])
        ->assertCanNotSeeTableRecords([$this->recurrenceOfB]);

    $this->get(route('filament.app.resources.recurrences.edit', $this->recurrenceOfB))->assertNotFound();
});

it('ambos editam a recorrência da conta compartilhada, escolhendo o alcance', function (string $who) {
    $this->actingAs($this->{$who});

    Livewire::test(EditRecurrence::class, ['record' => $this->sharedRecurrence->getRouteKey()])
        ->assertFormSet(['amount' => '100,00'])
        ->fillForm(['amount' => '120,00'])
        ->call('save')
        ->assertHasFormErrors(['apply_to_generated' => 'required'])
        ->fillForm(['amount' => '120,00', 'apply_to_generated' => '1'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Transaction::where('recurrence_id', $this->sharedRecurrence->id)->get()
        ->map(fn ($t) => $t->amount->getMinorAmount()->toInt())->unique()->all())->toBe([-12000]);
})->with(['userA', 'userB']);

it('cria conta fixa pelo formulário e os previstos aparecem nos lançamentos', function () {
    $this->actingAs($this->userA);

    Livewire::test(CreateRecurrencePage::class)
        ->fillForm([
            'description' => 'Condomínio',
            'account_id' => $this->shared->id,
            'category_id' => $this->category->id,
            'amount' => '650,00',
            'frequency' => 'monthly',
            'day_of_month' => 31,
            'start_date' => '2026-09-01',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $recurrence = Recurrence::where('description', 'Condomínio')->sole();
    $generated = Transaction::where('recurrence_id', $recurrence->id)->orderBy('date')->get();

    expect($generated->map(fn ($t) => $t->date->toDateString())->all())->toBe(['2026-09-30', '2026-10-31']);

    Livewire::test(ListTransactions::class)
        ->filterTable('recurrence_id', $recurrence->id)
        ->assertCanSeeTableRecords($generated)
        ->assertTableActionVisible('openRecurrence', $generated->first());
});

it('abre as telas de contas fixas', function () {
    $this->actingAs($this->userA);

    $this->get(route('filament.app.resources.recurrences.index'))->assertSuccessful()->assertSee('Mensal, dia 10');
    $this->get(route('filament.app.resources.recurrences.create'))->assertSuccessful();
    $this->get(route('filament.app.resources.recurrences.edit', $this->sharedRecurrence))->assertSuccessful();
});
