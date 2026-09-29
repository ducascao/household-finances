<?php

use App\Enums\AccountVisibility;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Budget;
use App\Models\Household;
use App\Models\ImportLine;
use App\Models\ImportRule;
use App\Models\InstallmentGroup;
use App\Models\Invoice;
use App\Models\Recurrence;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Storage;

it('cria o lar de demonstração com 2 usuários, contas e lançamentos', function () {
    Storage::fake('local');
    $this->seed(DemoSeeder::class);

    $household = Household::where('name', 'Casa Demo')->sole();
    $maria = User::where('email', 'maria@demo.local')->sole();

    expect($household->users()->count())->toBe(2)
        ->and(Account::where('visibility', AccountVisibility::Private)->count())->toBe(3)
        ->and(Transaction::count())->toBeGreaterThan(20)
        ->and(Transaction::scheduled()->count())->toBeGreaterThan(0)
        ->and(Transaction::overdue()->count())->toBeGreaterThan(0)
        ->and(Transaction::whereNotNull('transfer_id')->count() % 2)->toBe(0)
        ->and(Recurrence::count())->toBeGreaterThan(5)
        ->and(Transaction::whereNotNull('recurrence_id')->count())->toBeGreaterThan(5)
        ->and(Invoice::whereNotNull('paid_at')->count())->toBeGreaterThan(0)
        ->and(InstallmentGroup::count())->toBe(2)
        ->and(ImportRule::count())->toBeGreaterThan(3)
        ->and(ImportLine::where('status', 'match')->count())->toBe(1)
        ->and(Budget::whereDate('month', today()->startOfMonth())->count())->toBe(10)
        ->and(Attachment::where('disk', 'local')->count())->toBe(2);

    $this->actingAs($maria);

    expect(Account::pluck('name'))->not->toContain('Nubank Eduardo');
});
