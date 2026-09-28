<?php

use App\Domain\Household\CreateHousehold;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

it('abre todas as telas do painel', function () {
    $household = app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $user = User::where('email', 'eduardo@example.com')->sole();
    $user->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();

    $account = Account::factory()->ownedBy($user)->create();
    $category = Category::where('household_id', $household->id)->whereNotNull('parent_id')->first();
    $transaction = Transaction::factory()->forAccount($account)->withCategory($category, 1000)->create();

    $this->actingAs($user);

    foreach ([
        '/',
        route('filament.app.resources.transactions.index'),
        route('filament.app.resources.transactions.create'),
        route('filament.app.resources.transactions.edit', $transaction),
        route('filament.app.resources.accounts.index'),
        route('filament.app.resources.accounts.create'),
        route('filament.app.resources.accounts.edit', $account),
        route('filament.app.resources.categories.index'),
        route('filament.app.resources.categories.create'),
        route('filament.app.resources.categories.edit', $category),
    ] as $url) {
        $this->get($url)->assertSuccessful();
    }
});
