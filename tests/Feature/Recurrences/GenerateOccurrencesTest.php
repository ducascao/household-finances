<?php

use App\Domain\Debts\GenerateDebtInstallments;
use App\Domain\Recurrences\CreateRecurrence;
use App\Domain\Recurrences\DeleteRecurrence;
use App\Domain\Recurrences\GenerateOccurrences;
use App\Domain\Recurrences\UpdateRecurrence;
use App\Domain\Transactions\MarkAsPaid;
use App\Enums\TransactionStatus;
use App\Jobs\GenerateRecurringTransactions;
use App\Models\Account;
use App\Models\Category;
use App\Models\Household;
use App\Models\Recurrence;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');

    $this->household = Household::factory()->create();
    $this->user = User::factory()->inHousehold($this->household)->create();
    $this->account = Account::factory()->ownedBy($this->user)->create();
    $this->rent = Category::factory()->expense()->create(['household_id' => $this->household->id, 'name' => 'Aluguel']);
    $this->salary = Category::factory()->income()->create(['household_id' => $this->household->id, 'name' => 'Salário']);

    $this->create = fn (array $data = []) => app(CreateRecurrence::class)->execute($this->user, [
        'account_id' => $this->account->id,
        'category_id' => $this->rent->id,
        'amount' => 280000,
        'description' => 'Aluguel',
        'frequency' => 'monthly',
        'day_of_month' => 10,
        'start_date' => '2026-10-01',
        ...$data,
    ]);

    $this->dates = fn (Recurrence $r) => Transaction::withoutGlobalScopes()
        ->where('recurrence_id', $r->id)->orderBy('occurrence_date')
        ->pluck('occurrence_date')->map->toDateString()->all();
});

it('ao criar gera os previstos até hoje + 60 dias, ligados à recorrência', function () {
    $recurrence = ($this->create)();

    expect(($this->dates)($recurrence))->toBe(['2026-10-10', '2026-11-10'])
        ->and($recurrence->next_date->toDateString())->toBe('2026-12-10');

    $first = Transaction::where('recurrence_id', $recurrence->id)->orderBy('occurrence_date')->first();

    expect($first)
        ->status->toBe(TransactionStatus::Scheduled)
        ->and($first->due_date->toDateString())->toBe('2026-10-10')
        ->and($first->competence_date->toDateString())->toBe('2026-10-01')
        ->and($first->amount->getMinorAmount()->toInt())->toBe(-280000)
        ->and($first->recurrence->is($recurrence))->toBeTrue();
});

it('rodar o job duas vezes não duplica lançamentos', function () {
    $recurrence = ($this->create)();

    (new GenerateRecurringTransactions)->handle(app(GenerateOccurrences::class), app(GenerateDebtInstallments::class));
    (new GenerateRecurringTransactions)->handle(app(GenerateOccurrences::class), app(GenerateDebtInstallments::class));
    $this->artisan('app:generate-recurrences')->assertSuccessful();

    expect(($this->dates)($recurrence))->toBe(['2026-10-10', '2026-11-10']);

    Carbon::setTestNow('2026-10-15 10:00:00');
    app(GenerateOccurrences::class)->executeAll();
    app(GenerateOccurrences::class)->executeAll();

    expect(($this->dates)($recurrence))->toBe(['2026-10-10', '2026-11-10', '2026-12-10']);
});

it('o banco recusa a mesma ocorrência duas vezes', function () {
    $recurrence = ($this->create)();
    $existing = Transaction::where('recurrence_id', $recurrence->id)->first();

    $copy = $existing->replicate();
    $copy->household_id = $existing->household_id;
    $copy->save();
})->throws(UniqueConstraintViolationException::class);

it('respeita a data de fim', function () {
    $recurrence = ($this->create)(['start_date' => '2026-09-01', 'end_date' => '2026-10-31']);

    expect(($this->dates)($recurrence))->toBe(['2026-09-10', '2026-10-10']);

    Carbon::setTestNow('2027-01-01');
    app(GenerateOccurrences::class)->executeAll();

    expect(($this->dates)($recurrence))->toBe(['2026-09-10', '2026-10-10']);
});

it('início no passado gera os previstos desde o início (aparecem atrasados)', function () {
    $recurrence = ($this->create)(['start_date' => '2026-08-01']);

    expect(($this->dates)($recurrence))->toBe(['2026-08-10', '2026-09-10', '2026-10-10', '2026-11-10'])
        ->and(Transaction::overdue()->count())->toBe(2);
});

it('receita recorrente entra positiva', function () {
    $recurrence = ($this->create)(['category_id' => $this->salary->id, 'amount' => 850000, 'day_of_month' => 5]);

    expect(Transaction::where('recurrence_id', $recurrence->id)->first()->amount->getMinorAmount()->toInt())->toBe(850000);
});

it('lançamento gerado e excluído à mão não volta', function () {
    $recurrence = ($this->create)();
    Transaction::where('recurrence_id', $recurrence->id)->whereDate('occurrence_date', '2026-10-10')->delete();

    app(GenerateOccurrences::class)->executeAll();

    expect(($this->dates)($recurrence))->toBe(['2026-11-10']);
});

it('não gera para conta arquivada', function () {
    $recurrence = ($this->create)();
    $this->account->update(['archived_at' => now()]);

    Carbon::setTestNow('2026-11-15');
    app(GenerateOccurrences::class)->executeAll();

    expect(($this->dates)($recurrence))->toBe(['2026-10-10', '2026-11-10']);
});

it('editar só as próximas mantém os previstos já gerados', function () {
    $recurrence = ($this->create)();

    app(UpdateRecurrence::class)->execute($this->user, $recurrence, [
        'account_id' => $this->account->id, 'category_id' => $this->rent->id, 'amount' => 300000,
        'description' => 'Aluguel novo', 'frequency' => 'monthly', 'day_of_month' => 15, 'start_date' => '2026-10-01',
    ], applyToGenerated: false);

    expect(Transaction::where('recurrence_id', $recurrence->id)->pluck('description')->unique()->all())->toBe(['Aluguel'])
        ->and($recurrence->refresh()->next_date->toDateString())->toBe('2026-12-15');

    Carbon::setTestNow('2026-10-20');
    app(GenerateOccurrences::class)->executeAll();

    expect(($this->dates)($recurrence))->toBe(['2026-10-10', '2026-11-10', '2026-12-15'])
        ->and(Transaction::where('occurrence_date', '2026-12-15')->sole()->amount->getMinorAmount()->toInt())->toBe(-300000);
});

it('editar aplicando aos gerados refaz os previstos futuros e preserva pagos e atrasados', function () {
    $recurrence = ($this->create)(['start_date' => '2026-08-01']);
    // 10/08 fica pago, 10/09 fica atrasado, 10/10 e 10/11 são futuros.
    $august = Transaction::where('recurrence_id', $recurrence->id)->whereDate('occurrence_date', '2026-08-10')->sole();
    app(MarkAsPaid::class)->execute($this->user, $august, Carbon::parse('2026-08-10'));

    app(UpdateRecurrence::class)->execute($this->user, $recurrence, [
        'account_id' => $this->account->id, 'category_id' => $this->rent->id, 'amount' => 300000,
        'description' => 'Aluguel reajustado', 'frequency' => 'monthly', 'day_of_month' => 5, 'start_date' => '2026-08-01',
    ], applyToGenerated: true);

    $all = Transaction::where('recurrence_id', $recurrence->id)->orderBy('occurrence_date')->get();

    expect($all->map(fn ($t) => [$t->occurrence_date->toDateString(), $t->description, $t->amount->getMinorAmount()->toInt(), $t->status->value])->all())->toBe([
        ['2026-08-10', 'Aluguel', -280000, 'paid'],
        ['2026-09-10', 'Aluguel', -280000, 'scheduled'],
        ['2026-10-05', 'Aluguel reajustado', -300000, 'scheduled'],
        ['2026-11-05', 'Aluguel reajustado', -300000, 'scheduled'],
    ]);
});

it('excluir recorrência pode apagar os previstos futuros e mantém o resto', function (bool $deleteFuture, array $expected) {
    $recurrence = ($this->create)(['start_date' => '2026-09-01']);

    app(DeleteRecurrence::class)->execute($this->user, $recurrence, $deleteFuture);

    expect(Recurrence::count())->toBe(0)
        ->and(Transaction::orderBy('date')->get()->map(fn ($t) => [$t->date->toDateString(), $t->recurrence_id])->all())->toBe($expected);
})->with([
    'apagando os futuros' => [true, [['2026-09-10', null]]],
    'mantendo tudo' => [false, [['2026-09-10', null], ['2026-10-10', null], ['2026-11-10', null]]],
]);

it('o job está agendado diariamente', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->description, GenerateRecurringTransactions::class));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 1 * * *');
});
