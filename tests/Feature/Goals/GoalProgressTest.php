<?php

use App\Domain\Goals\GoalProgress;
use App\Domain\Goals\SaveGoal;
use App\Domain\Household\CreateHousehold;
use App\Domain\Investments\ManageOperations;
use App\Domain\Investments\ManageValuations;
use App\Domain\Investments\SaveAsset;
use App\Enums\AccountType;
use App\Enums\GoalStatus;
use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Carbon::setTestNow('2026-09-30 10:00:00');

    $household = app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $this->user = User::where('email', 'eduardo@example.com')->sole();
    $this->maria = User::factory()->inHousehold($household)->create();

    // Poupança compartilhada R$ 8.000; conta USD US$ 500 × 5,00 = R$ 2.500; CDB com saldo informado R$ 5.000
    $this->savings = Account::factory()->ownedBy($this->user)->shared()->create(['name' => 'Poupança', 'type' => AccountType::Savings, 'initial_balance' => 800000]);
    $this->usd = Account::factory()->ownedBy($this->user)->shared()->create(['name' => 'Wise', 'currency' => 'USD', 'initial_balance' => 50000]);
    $this->personal = Account::factory()->ownedBy($this->user)->private()->create(['name' => 'Pessoal', 'initial_balance' => 100000]);
    ExchangeRate::create(['currency' => 'USD', 'date' => '2026-09-29', 'rate' => '5.00']);

    $broker = Account::factory()->ownedBy($this->user)->shared()->create(['name' => 'XP', 'type' => AccountType::Brokerage]);
    $this->cdb = app(SaveAsset::class)->execute($this->user, ['account_id' => $broker->id, 'type' => 'fixed_income', 'name' => 'CDB Reserva']);
    app(ManageOperations::class)->register($this->user, $this->cdb, ['type' => 'contribution', 'date' => '2026-06-01', 'amount' => 480000]);
    app(ManageValuations::class)->save($this->user, $this->cdb, Carbon::parse('2026-09-29'), 500000);

    $this->goal = fn (array $data = [], ?User $actor = null) => app(SaveGoal::class)->execute($actor ?? $this->user, [
        'name' => 'Reserva de emergência', 'target' => 3000000, 'start_date' => '2026-03-31', 'deadline' => '2027-03-31',
        'visibility' => 'shared', 'account_ids' => [$this->savings->id, $this->usd->id], 'asset_ids' => [$this->cdb->id], ...$data,
    ]);
});

it('progresso e ritmo mensal corretos', function () {
    $progress = new GoalProgress(($this->goal)());

    // progresso = 8.000 + 2.500 + 5.000 = 15.500; falta 14.500
    // meses: set/2026 → mar/2027 = 6; ritmo = 14.500 ÷ 6 = 2.416,666… → 2.416,67
    // linha reta: 365 dias, 183 decorridos (31/03 → 30/09) → esperado 30.000 × 183/365 = 15.041,10 → no ritmo
    expect($progress->sources)->toBe(['Conta Poupança' => 800000, 'Conta Wise' => 250000, 'CDB Reserva' => 500000])
        ->and($progress->current)->toBe(1550000)
        ->and($progress->remaining())->toBe(1450000)
        ->and($progress->percent())->toBe(51.7)
        ->and($progress->monthsLeft())->toBe(6)
        ->and($progress->monthlyPace())->toBe(241667)
        ->and($progress->expectedToday())->toBe(1504110)
        ->and($progress->status())->toBe(GoalStatus::OnTrack);
});

it('atrasada, atingida, prazo vencido e virada de ano', function () {
    $goal = ($this->goal)(['target' => 5000000]);  // esperado hoje 25.068,49 > 15.500 → atrasada
    expect((new GoalProgress($goal))->status())->toBe(GoalStatus::Behind);

    $goal->update(['target' => 1500000]);
    $achieved = new GoalProgress($goal->refresh());
    expect($achieved->status())->toBe(GoalStatus::Achieved)->and($achieved->monthlyPace())->toBe(0);

    $goal->update(['target' => 5000000, 'deadline' => '2026-09-15']);
    expect((new GoalProgress($goal->refresh()))->status())->toBe(GoalStatus::Expired);

    // prazo no mesmo mês ainda dá 1 mês; de dezembro para janeiro também
    $goal->update(['deadline' => '2026-09-30']);
    expect((new GoalProgress($goal->refresh()))->monthsLeft())->toBe(1);
    Carbon::setTestNow('2026-12-10 10:00:00');
    $goal->update(['deadline' => '2027-01-20']);
    expect((new GoalProgress($goal->refresh()))->monthsLeft())->toBe(1);
});

it('meta compartilhada não vincula conta pessoal; meta pessoal pode', function () {
    expect(fn () => ($this->goal)(['account_ids' => [$this->personal->id]]))->toThrow(ValidationException::class, 'contas compartilhadas');

    $personal = ($this->goal)(['name' => 'Viagem', 'visibility' => 'private', 'account_ids' => [$this->personal->id, $this->savings->id], 'asset_ids' => []]);

    expect((new GoalProgress($personal))->current)->toBe(100000 + 800000);
});

it('meta pessoal de A não aparece para B', function () {
    ($this->goal)(['name' => 'Viagem', 'visibility' => 'private', 'account_ids' => [$this->personal->id], 'asset_ids' => []]);
    ($this->goal)();

    $this->actingAs($this->maria);

    expect(Goal::pluck('name')->all())->toBe(['Reserva de emergência']);
});

it('exige vínculo e prazo no futuro', function () {
    expect(fn () => ($this->goal)(['account_ids' => [], 'asset_ids' => []]))->toThrow(ValidationException::class, 'Vincule ao menos')
        ->and(fn () => ($this->goal)(['deadline' => '2026-01-01']))->toThrow(ValidationException::class, 'passado');
});
