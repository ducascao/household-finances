<?php

use App\Domain\Household\CreateHousehold;
use App\Enums\TransactionStatus;
use App\Filament\Monthly\ChartFormatting;
use App\Filament\Monthly\MonthTotalsWidget;
use App\Filament\NetWorth\CompositionWidget;
use App\Filament\NetWorth\NetWorthStatsWidget;
use App\Filament\Pages\PortfolioPage;
use App\Filament\Portfolio\PortfolioTotalsWidget;
use App\Filament\Widgets\AccountBalancesWidget;
use App\Filament\Widgets\BillsOverviewWidget;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Filament\Pages\Dashboard;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Textos dentro de elementos marcados como sensíveis (borrados no modo privacidade).
 */
function sensitiveTexts(string $html): string
{
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>');
    $nodes = (new DOMXPath($dom))->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' fc-sensitive ')]");

    return collect($nodes === false ? [] : iterator_to_array($nodes))
        ->map(fn (DOMNode $node): string => preg_replace('/\s+/u', ' ', $node->textContent) ?? '')
        ->implode(' | ');
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-02 10:00:00');

    app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $this->user = User::where('email', 'eduardo@example.com')->sole();
    $this->user->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
    $this->account = Account::factory()->ownedBy($this->user)->shared()->create(['name' => 'Conjunta', 'initial_balance' => 523456]);

    $this->actingAs($this->user);
});

it('mostra o botão do modo privacidade e aplica o estado salvo no navegador', function () {
    $this->get(Dashboard::getUrl())
        ->assertSuccessful()
        ->assertSee('Esconder valores')
        ->assertSee('Mostrar valores')
        ->assertSee("localStorage.getItem(key) === '1'", false)
        ->assertSee('.fc-private .fc-sensitive', false);
});

it('marca os saldos das contas como sensíveis, mas não os nomes', function () {
    $sensitive = sensitiveTexts(Livewire::test(AccountBalancesWidget::class)->html());

    expect($sensitive)
        ->toContain('R$ 5.234,56')
        ->not->toContain('Conjunta')
        ->not->toContain('Total atual');
});

it('marca os indicadores de contas a pagar e do resumo do mês', function () {
    Transaction::factory()->forAccount($this->account)->create(['amount' => -12345, 'status' => TransactionStatus::Scheduled, 'date' => '2026-10-05', 'due_date' => '2026-10-05', 'competence_date' => '2026-10-01']);

    expect(sensitiveTexts(Livewire::test(BillsOverviewWidget::class)->html()))
        ->toContain('R$ 123,45')
        ->not->toContain('lançamento');

    expect(sensitiveTexts(Livewire::test(MonthTotalsWidget::class, ['pageFilters' => ['month' => '2026-10']])->html()))
        ->toContain('R$ 123,45')
        ->not->toContain('Saídas');
});

it('marca patrimônio e composição, mas deixa o peso em percentual visível', function () {
    expect(sensitiveTexts(Livewire::test(NetWorthStatsWidget::class, ['pageFilters' => ['view' => 'me']])->html()))
        ->toContain('R$ 5.234,56')
        ->not->toContain('Patrimônio líquido');

    expect(sensitiveTexts(Livewire::test(CompositionWidget::class, ['pageFilters' => ['view' => 'me']])->html()))
        ->toContain('R$ 5.234,56')
        ->not->toContain('% dos ativos');
});

it('carteira abre com os totais marcados', function () {
    Livewire::test(PortfolioPage::class)->assertSuccessful();

    expect(Livewire::test(PortfolioTotalsWidget::class)->html())->toContain('fc-sensitive');
});

it('gráficos trocam eixo e tooltip por R$ ••• no modo privacidade', function () {
    expect((string) ChartFormatting::options())
        ->toContain('window.fcPrivacy?.isOn()')
        ->toContain('R$ •••');
});
