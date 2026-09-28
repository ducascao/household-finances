<?php

use App\Enums\AccountVisibility;
use App\Models\Account;
use App\Models\Household;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\DemoSeeder;

it('cria o lar de demonstração com 2 usuários, contas e lançamentos', function () {
    $this->seed(DemoSeeder::class);

    $household = Household::where('name', 'Casa Demo')->sole();
    $maria = User::where('email', 'maria@demo.local')->sole();

    expect($household->users()->count())->toBe(2)
        ->and(Account::where('visibility', AccountVisibility::Private)->count())->toBe(2)
        ->and(Transaction::count())->toBeGreaterThan(20);

    $this->actingAs($maria);

    expect(Account::pluck('name'))->not->toContain('Nubank Eduardo');
});
