<?php

use App\Filament\Resources\Transactions\Pages\ListTransactions;
use App\Models\Account;
use App\Models\Category;
use App\Models\Household;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-23 10:00:00');

    $household = Household::factory()->create();
    $this->user = User::factory()->withTwoFactor()->inHousehold($household)->create();
    $account = Account::factory()->ownedBy($this->user)->private()->create();
    $category = Category::factory()->expense()->create(['household_id' => $household->id]);

    // 12 dias antes de hoje (2 lançamentos cada), 2 hoje e 3 depois: 29 no total.
    $add = fn (string $date, string $description) => Transaction::factory()->forAccount($account)->withCategory($category, 1000)
        ->create(['date' => $date, 'competence_date' => $date, 'description' => $description]);

    foreach (range(1, 12) as $day) {
        $add(sprintf('2026-10-%02d', $day), "Antigo {$day}a");
        $add(sprintf('2026-10-%02d', $day), "Antigo {$day}b");
    }

    $this->today = [$add('2026-10-23', 'Hoje 1'), $add('2026-10-23', 'Hoje 2')];
    $this->future = $add('2026-10-30', 'Futuro');
    $this->oldest = Transaction::where('description', 'Antigo 1a')->sole();

    $this->actingAs($this->user);
});

it('abre em ordem crescente de data, já na página com os lançamentos de hoje', function () {
    $component = Livewire::test(ListTransactions::class);
    $perPage = (int) $component->instance()->getTableRecordsPerPage();

    $component->assertSet('paginators.page', intdiv(24, $perPage) + 1)
        ->assertCanSeeTableRecords($this->today)
        ->assertCanNotSeeTableRecords([$this->oldest]);

    $records = $component->instance()->getTableRecords()->pluck('date')->map->format('Y-m-d')->all();
    $sorted = $records;
    sort($sorted);
    expect($records)->toBe($sorted);
});

it('respeita a página pedida na URL', function () {
    Livewire::withQueryParams(['page' => 1])->test(ListTransactions::class)
        ->assertCanSeeTableRecords([$this->oldest]);
});
