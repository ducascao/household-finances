<?php

use App\Domain\Household\CreateHousehold;
use App\Domain\Investments\ManageOperations;
use App\Domain\Investments\PriceBook;
use App\Domain\Investments\SaveAsset;
use App\Domain\Reports\Email\SendFinancialReport;
use App\Domain\Transactions\CreateTransaction;
use App\Enums\AccountType;
use App\Enums\ReportView;
use App\Filament\Pages\SendReport;
use App\Mail\FinancialReportMail;
use App\Models\Account;
use App\Models\Category;
use App\Models\Household;
use App\Models\ReportEmail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-23 10:00:00');
    Mail::fake();

    $household = $this->household = app(CreateHousehold::class)->execute('Casa Exemplo', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $this->user = User::where('email', 'eduardo@example.com')->sole();
    $this->user->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
    $this->maria = User::factory()->withTwoFactor()->inHousehold($household)->create(['name' => 'Maria', 'email' => 'maria@example.com']);

    $shared = Account::factory()->ownedBy($this->user)->shared()->create(['name' => 'Conjunta', 'initial_balance' => 100000]);
    $mine = Account::factory()->ownedBy($this->user)->private()->create(['name' => 'Reserva do Eduardo', 'initial_balance' => 500000]);
    $hers = Account::factory()->ownedBy($this->maria)->private()->create(['name' => 'Conta da Maria', 'initial_balance' => 70000]);
    $market = Category::where('household_id', $household->id)->where('name', 'Mercado')->sole();

    $add = fn (User $user, Account $account, int $amount, string $date) => app(CreateTransaction::class)->execute($user, [
        'account_id' => $account->id, 'category_id' => $market->id, 'amount' => $amount, 'date' => $date, 'description' => 'Mercado',
    ]);
    $add($this->user, $shared, 45000, '2026-10-10');
    $add($this->user, $mine, 12345, '2026-10-11');
    $add($this->maria, $hers, 999, '2026-10-12');

    $sharedBroker = Account::factory()->ownedBy($this->user)->shared()->create(['type' => AccountType::Brokerage, 'name' => 'Corretora Conjunta']);
    $privateBroker = Account::factory()->ownedBy($this->user)->private()->create(['type' => AccountType::Brokerage, 'name' => 'Corretora Pessoal']);

    foreach ([[$sharedBroker, 'PETR4', '100', '30', '33'], [$privateBroker, 'VALE3', '10', '50', '60']] as [$broker, $ticker, $quantity, $cost, $price]) {
        $asset = app(SaveAsset::class)->execute($this->user, ['account_id' => $broker->id, 'type' => 'stock', 'ticker' => $ticker, 'name' => $ticker]);
        app(ManageOperations::class)->register($this->user, $asset, ['type' => 'buy', 'date' => '2026-10-01', 'quantity' => $quantity, 'unit_price' => $cost]);
        app(PriceBook::class)->setManual($this->user, $asset, Carbon::parse('2026-10-22'), $price);
    }

    $this->actingAs($this->user);

    $this->send = fn (array $data): ReportEmail => app(SendFinancialReport::class)->execute($this->user, [
        'recipients' => ['maria@example.com'],
        'sections' => ['monthly_summary', 'net_worth', 'portfolio'],
        'view' => 'shared',
        'month' => '2026-10',
        ...$data,
    ]);

    $this->html = function (string $to): string {
        $html = null;
        Mail::assertQueued(FinancialReportMail::class, function (FinancialReportMail $mail) use ($to, &$html): bool {
            if ($mail->hasTo($to)) {
                $html = $mail->render();
            }

            return $mail->hasTo($to);
        });

        return (string) $html;
    };
});

it('"só o compartilhado" não mostra nada das contas, ativos ou gastos pessoais', function () {
    ($this->send)([]);

    $html = ($this->html)('maria@example.com');

    expect($html)
        ->toContain('Resumo do mês · Outubro/2026')
        ->toContain('Conjunta')
        ->toContain('R$ 450,00')
        ->toContain('PETR4')
        ->toContain('Patrimônio líquido · 23/10/2026')
        ->toContain('Só contas, bens e investimentos compartilhados do lar.')
        ->not->toContain('Reserva do Eduardo')
        ->not->toContain('Corretora Pessoal')
        ->not->toContain('VALE3')
        ->not->toContain('R$ 573,45')
        ->not->toContain('R$ 123,45')
        ->not->toContain('Conta da Maria');

    // Depois do envio, a tela volta a ver as contas pessoais normalmente.
    expect(Account::pluck('name')->all())->toContain('Reserva do Eduardo');
});

it('"minha visão completa" inclui as contas pessoais de quem envia, mas nunca as de outra pessoa', function () {
    ($this->send)(['view' => 'mine']);

    expect(($this->html)('maria@example.com'))
        ->toContain('Reserva do Eduardo')
        ->toContain('VALE3')
        ->toContain('R$ 573,45')
        ->toContain('Inclui as contas pessoais de Eduardo.')
        ->not->toContain('Conta da Maria')
        ->not->toContain('R$ 9,99');
});

it('envia só as partes escolhidas, um e-mail por destinatário, e registra o envio', function () {
    $sent = ($this->send)([
        'recipients' => [' Maria@Example.com ', 'avo@example.com', 'maria@example.com'],
        'sections' => ['net_worth'],
        'month' => null,
        'message' => 'Oi, segue o patrimônio.',
    ]);

    Mail::assertQueuedCount(2);
    Mail::assertQueued(FinancialReportMail::class, fn (FinancialReportMail $mail): bool => $mail->hasTo('avo@example.com')
        && $mail->report->subject() === 'Finanças de Casa: Patrimônio (23/10/2026 10:00)');

    expect(($this->html)('avo@example.com'))
        ->toContain('Oi, segue o patrimônio.')
        ->toContain('Patrimônio líquido')
        ->not->toContain('Resumo do mês')
        ->not->toContain('Ativos (Carteira)');

    expect($sent->fresh())
        ->recipients->toBe(['maria@example.com', 'avo@example.com'])
        ->sections->toBe(['net_worth'])
        ->view->toBe(ReportView::Shared)
        ->month->toBeNull()
        ->sender_id->toBe($this->user->id)
        ->household_id->toBe($this->household->id);
});

it('recusa envio sem destinatário, com e-mail inválido, sem partes ou sem o mês do resumo', function (array $data, string $field) {
    try {
        ($this->send)($data);
        $this->fail('Deveria recusar');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }

    Mail::assertNothingQueued();
    expect(ReportEmail::count())->toBe(0);
})->with([
    'sem destinatário' => [['recipients' => []], 'recipients'],
    'e-mail inválido' => [['recipients' => ['nao-e-email']], 'recipients.0'],
    'sem partes' => [['sections' => []], 'sections'],
    'sem mês' => [['month' => null], 'month'],
]);

it('a tela envia, lista só os envios de quem está logado e oferece só as pessoas do lar', function () {
    $outsider = User::factory()->withTwoFactor()->inHousehold(Household::factory()->create())->create(['name' => 'Pessoa de Fora']);
    ReportEmail::factory()->create(['sender_id' => $this->maria->id, 'recipients' => ['segredo@example.com']]);

    $this->get(SendReport::getUrl())->assertSuccessful()->assertSee('E-mail ainda não configurado');

    expect(SendReport::memberOptions($this->user))->toBe([
        'eduardo@example.com' => 'Eduardo (eduardo@example.com) · você',
        'maria@example.com' => 'Maria (maria@example.com)',
    ])->not->toHaveKey($outsider->email);

    Livewire::test(SendReport::class)
        ->callAction('send', ['members' => ['maria@example.com'], 'emails' => ['avo@example.com'], 'sections' => ['portfolio'], 'view' => 'shared'])
        ->assertHasNoActionErrors()
        ->assertNotified('Resumo enviado para 2 destinatário(s)')
        ->assertSee('maria@example.com, avo@example.com')
        ->assertDontSee('segredo@example.com');

    Mail::assertQueuedCount(2);
});
