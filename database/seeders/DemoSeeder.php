<?php

namespace Database\Seeders;

use App\Domain\Accounts\CreateAccount;
use App\Domain\Attachments\AttachFile;
use App\Domain\Budgets\CopyPreviousMonth;
use App\Domain\Budgets\SaveBudget;
use App\Domain\CreditCard\CreateInstallmentPurchase;
use App\Domain\CreditCard\PayInvoice;
use App\Domain\Debts\GenerateDebtInstallments;
use App\Domain\Debts\ManageDebts;
use App\Domain\Goals\SaveGoal;
use App\Domain\Household\AddMember;
use App\Domain\Household\CreateHousehold;
use App\Domain\Import\BankPresets;
use App\Domain\Import\ImportStatement;
use App\Domain\Import\SaveImportProfile;
use App\Domain\Import\SaveImportRule;
use App\Domain\Investments\ManageIncomes;
use App\Domain\Investments\ManageOperations;
use App\Domain\Investments\ManageValuations;
use App\Domain\Investments\SaveAsset;
use App\Domain\NetWorth\TakeSnapshots;
use App\Domain\Recurrences\CreateRecurrence;
use App\Domain\Transactions\CreateTransaction;
use App\Domain\Transactions\MarkAsPaid;
use App\Domain\Transfers\CreateTransfer;
use App\Enums\AccountType;
use App\Enums\AccountVisibility;
use App\Enums\ImportFormat;
use App\Enums\PrepaymentMode;
use App\Enums\PriceSource;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Asset;
use App\Models\AssetPrice;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\Debt;
use App\Models\DebtInstallment;
use App\Models\ExchangeRate;
use App\Models\Household;
use App\Models\InterestRate;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Lar de exemplo com 2 usuários, contas pessoais e compartilhadas, 6 meses de lançamentos,
 * contas previstas (atrasadas e a vencer), transferências, contas fixas, cartões de crédito com faturas
 * importação (regras, perfil de CSV e um lote em revisão), orçamentos dos últimos meses e comprovantes
 * (no disco local, já que os dados de exemplo não têm Google Drive conectado), uma carteira B3,
 * renda fixa, previdência, ativos no exterior, dívidas, metas e o histórico do patrimônio.
 * Senha dos usuários: "password". O 2FA é configurado no primeiro login.
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public function run(CreateHousehold $createHousehold, AddMember $addMember, CreateAccount $createAccount): void
    {
        $household = $createHousehold->execute('Casa Demo', 'Eduardo', 'eduardo@demo.local', self::PASSWORD);
        $eduardo = User::where('email', 'eduardo@demo.local')->sole();
        $maria = $addMember->execute($household, 'Maria', 'maria@demo.local', self::PASSWORD);

        $account = fn (User $owner, string $name, AccountType $type, AccountVisibility $visibility, int $initial) => $createAccount->execute($owner, [
            'name' => $name,
            'type' => $type,
            'visibility' => $visibility,
            'currency' => 'BRL',
            'initial_balance' => $initial,
        ]);

        $nubank = $account($eduardo, 'Nubank Eduardo', AccountType::Checking, AccountVisibility::Private, 250000);
        $itau = $account($maria, 'Itaú Maria', AccountType::Checking, AccountVisibility::Private, 300000);
        $joint = $account($eduardo, 'Conta conjunta', AccountType::Checking, AccountVisibility::Shared, 5000000);
        $wallet = $account($maria, 'Carteira', AccountType::Cash, AccountVisibility::Shared, 350000);
        $savings = $account($eduardo, 'Poupança', AccountType::Savings, AccountVisibility::Shared, 1500000);

        for ($monthsAgo = 5; $monthsAgo >= 0; $monthsAgo--) {
            $month = now()->startOfMonth()->subMonths($monthsAgo);

            $this->add($household, $eduardo, $nubank, 'Salário', 5, 850000, 'Salário', $month);
            $this->add($household, $maria, $itau, 'Salário', 5, 720000, 'Salário', $month);
            $this->add($household, $eduardo, $joint, 'Aluguel', 10, 280000, 'Aluguel', $month);
            $this->add($household, $maria, $joint, 'Condomínio', 10, 65000, 'Condomínio', $month);
            $this->add($household, $eduardo, $joint, 'Luz', 15, random_int(18000, 26000), 'Conta de luz', $month);
            $this->add($household, $maria, $joint, 'Internet', 15, 11990, 'Internet', $month);
            $this->add($household, $maria, $joint, 'Mercado', 8, random_int(60000, 90000), 'Mercado do mês', $month, ['mercado']);
            $this->add($household, $eduardo, $joint, 'Mercado', 22, random_int(15000, 30000), 'Feira', $month);
            $this->add($household, $eduardo, $nubank, 'Combustível', 12, random_int(20000, 30000), 'Posto', $month, ['carro']);
            $this->add($household, $maria, $itau, 'Farmácia', 18, random_int(3000, 12000), 'Farmácia', $month);
            $this->add($household, $eduardo, $nubank, 'Assinaturas', 3, 5590, 'Streaming', $month);
            $this->add($household, $maria, $wallet, 'Restaurante', 20, random_int(8000, 20000), 'Almoço de domingo', $month, ['lazer']);
            $this->add($household, $eduardo, $joint, 'Rendimentos', 28, random_int(4000, 9000), 'Rendimento poupança', $month);
            $this->transfer($eduardo, $nubank, $joint, 6, 520000, 'Aporte na conjunta', $month);
            $this->transfer($maria, $itau, $joint, 6, 430000, 'Aporte na conjunta', $month);
        }

        // Contas previstas: uma atrasada, algumas vencendo nos próximos dias e o mês seguinte.
        $this->scheduled($household, $maria, $joint, 'IPTU', today()->subDays(3), 42000, 'IPTU (parcela)');
        $this->scheduled($household, $eduardo, $nubank, 'Telefone', today()->addDays(2), 6990, 'Celular');
        $this->scheduled($household, $maria, $joint, 'Plano de saúde', today()->addDays(5), 89000, 'Plano de saúde');
        $this->cards($household, $eduardo, $maria, $joint);

        // Contas fixas a partir do mês que vem (os meses anteriores já estão lançados acima).
        $nextMonth = today()->addMonthNoOverflow()->startOfMonth();
        $this->recurrence($household, $eduardo, $joint, 'Aluguel', 280000, 'monthly', 10, $nextMonth);
        $this->recurrence($household, $maria, $joint, 'Condomínio', 65000, 'monthly', 10, $nextMonth);
        $this->recurrence($household, $maria, $joint, 'Internet', 11990, 'monthly', 15, $nextMonth);
        $this->recurrence($household, $eduardo, $joint, 'Luz', 22000, 'monthly', 15, $nextMonth, estimate: true);
        $this->recurrence($household, $eduardo, $nubank, 'Salário', 850000, 'monthly', 5, $nextMonth);
        $this->recurrence($household, $maria, $itau, 'Salário', 720000, 'monthly', 5, $nextMonth);
        $this->recurrence($household, $eduardo, $joint, 'IPVA', 180000, 'yearly', 20, today()->addMonthsNoOverflow(1)->day(20));
        $this->recurrence($household, $maria, $joint, 'Serviços domésticos', 18000, 'weekly', null, today()->next(Carbon::FRIDAY), description: 'Diarista');
        $this->imports($household, $eduardo, $joint);
        $this->budgets($household);
        $this->attachments($eduardo);
        $this->portfolio($eduardo, $joint);
        $this->fixedIncome($eduardo);
        $this->foreign($eduardo);
        $this->debts($household, $eduardo);

        $this->goals($eduardo, $maria);

        // Histórico do patrimônio: fotografias dos últimos 6 meses (mesmo cálculo do comando app:net-worth).
        for ($month = today()->startOfMonth()->subMonthsNoOverflow(6); $month->lte(today()); $month->addMonthNoOverflow()) {
            app(TakeSnapshots::class)->forMonth($month, $household);
        }

        app(CreateTransfer::class)->execute($eduardo, [
            'from_account_id' => $joint->id,
            'to_account_id' => $savings->id,
            'amount' => 100000,
            'status' => 'scheduled',
            'due_date' => today()->addDays(4)->toDateString(),
            'description' => 'Reserva de emergência',
        ]);
    }

    private function scheduled(Household $household, User $payer, Account $account, string $category, Carbon $dueDate, int $amount, string $description): void
    {
        app(CreateTransaction::class)->execute($payer, [
            'account_id' => $account->id,
            'category_id' => Category::where('household_id', $household->id)->where('name', $category)->valueOrFail('id'),
            'amount' => $amount,
            'status' => 'scheduled',
            'due_date' => $dueDate->toDateString(),
            'description' => $description,
            'paid_by' => $payer->id,
        ]);
    }

    /**
     * Cartão compartilhado e cartão pessoal da Maria, com compras à vista, parcelada, estorno e faturas passadas pagas.
     */
    private function cards(Household $household, User $eduardo, User $maria, Account $joint): void
    {
        $sharedCard = app(CreateAccount::class)->execute($eduardo, [
            'name' => 'Cartão conjunto', 'type' => AccountType::CreditCard, 'visibility' => AccountVisibility::Shared,
            'currency' => 'BRL', 'card_closing_day' => 25, 'card_due_day' => 5, 'card_limit' => 1500000,
        ]);
        $mariaCard = app(CreateAccount::class)->execute($maria, [
            'name' => 'Cartão Maria', 'type' => AccountType::CreditCard, 'visibility' => AccountVisibility::Private,
            'currency' => 'BRL', 'card_closing_day' => 3, 'card_due_day' => 10, 'card_limit' => 600000,
        ]);

        $category = fn (string $name): int => Category::where('household_id', $household->id)->where('name', $name)->valueOrFail('id');
        $buy = fn (User $payer, Account $card, string $categoryName, int $daysAgo, int $amount, string $description, bool $refund = false) => app(CreateTransaction::class)->execute($payer, [
            'account_id' => $card->id, 'category_id' => $category($categoryName), 'amount' => $amount,
            'date' => today()->subDays($daysAgo)->toDateString(), 'description' => $description, 'paid_by' => $payer->id,
            'is_refund' => $refund,
        ]);

        $buy($eduardo, $sharedCard, 'Mercado', 55, 42350, 'Atacadão');
        $buy($maria, $sharedCard, 'Restaurante', 48, 18700, 'Jantar');
        $buy($eduardo, $sharedCard, 'Combustível', 34, 25000, 'Posto');
        $buy($maria, $sharedCard, 'Farmácia', 20, 8990, 'Drogaria');
        $buy($eduardo, $sharedCard, 'Eletrônicos', 12, 29900, 'Fone de ouvido');
        $buy($eduardo, $sharedCard, 'Eletrônicos', 9, 29900, 'Estorno fone de ouvido', refund: true);
        $buy($maria, $sharedCard, 'Delivery', 2, 6450, 'Pizza');
        $buy($maria, $mariaCard, 'Roupas', 30, 35990, 'Loja de roupas');
        $buy($maria, $mariaCard, 'Cursos', 6, 19900, 'Curso online');

        app(CreateInstallmentPurchase::class)->execute($eduardo, [
            'account_id' => $sharedCard->id, 'category_id' => $category('Casa'), 'amount' => 360000, 'installments' => 10,
            'date' => today()->subDays(40)->toDateString(), 'description' => 'Geladeira', 'paid_by' => $eduardo->id,
        ]);
        app(CreateInstallmentPurchase::class)->execute($maria, [
            'account_id' => $mariaCard->id, 'category_id' => $category('Eletrônicos'), 'amount' => 100000, 'installments' => 3,
            'date' => today()->subDays(25)->toDateString(), 'description' => 'Celular', 'paid_by' => $maria->id,
        ]);

        // Faturas já vencidas foram pagas pela conta conjunta (a da Maria, pela conta dela).
        foreach ([[$sharedCard, $eduardo, $joint], [$mariaCard, $maria, null]] as [$card, $payer, $from]) {
            $creditCard = CreditCard::where('account_id', $card->id)->sole();
            $from ??= Account::where('owner_id', $payer->id)->where('type', AccountType::Checking->value)->firstOrFail();

            Invoice::where('credit_card_id', $creditCard->id)
                ->whereDate('due_date', '<', today())
                ->whereNull('paid_at')
                ->orderBy('due_date')
                ->get()
                ->each(fn (Invoice $invoice) => app(PayInvoice::class)->execute($payer, $invoice, $from->id, $invoice->due_date));
        }
    }

    /**
     * Regras de importação, perfil de CSV da conta conjunta e um extrato em revisão
     * (com uma linha que corresponde ao IPTU previsto e atrasado).
     */
    private function imports(Household $household, User $eduardo, Account $joint): void
    {
        $category = fn (string $name): int => Category::where('household_id', $household->id)->where('name', $name)->valueOrFail('id');

        foreach ([
            ['pattern' => 'pagamento recebido', 'ignore' => true],
            ['pattern' => 'salario', 'category_id' => $category('Salário')],
            ['pattern' => 'ifood', 'category_id' => $category('Delivery'), 'description' => 'iFood'],
            ['pattern' => 'posto', 'category_id' => $category('Combustível')],
            ['pattern' => 'drogaria', 'category_id' => $category('Farmácia')],
            ['pattern' => 'padaria', 'category_id' => $category('Mercado'), 'description' => 'Padaria'],
        ] as $rule) {
            app(SaveImportRule::class)->execute($household->id, $rule);
        }

        app(SaveImportProfile::class)->execute($eduardo, [
            ...BankPresets::PRESETS['nubank_account']['profile'], 'account_id' => $joint->id, 'name' => 'Nubank — conta',
        ]);

        $day = fn (int $daysAgo): string => today()->subDays($daysAgo)->format('d/m/Y');
        $csv = implode("\n", [
            'Data,Valor,Identificador,Descrição',
            "{$day(2)},-420.00,demo-1,Pagamento de boleto efetuado - PREFEITURA IPTU",
            "{$day(2)},-23.50,demo-2,Compra no débito - PADARIA CENTRAL",
            "{$day(1)},-64.90,demo-3,Compra no débito - IFOOD",
            "{$day(1)},-150.00,demo-4,Transferência enviada pelo Pix - FULANO",
        ]);

        app(ImportStatement::class)->execute($eduardo, $joint->id, $csv, 'extrato-conjunta.csv', ImportFormat::Csv);
    }

    /**
     * Orçamento dos últimos 3 meses e do mês corrente (copiado mês a mês), com categorias estouradas e em atenção.
     */
    private function budgets(Household $household): void
    {
        $first = today()->startOfMonth()->subMonthsNoOverflow(3);
        $category = fn (string $name): int => Category::where('household_id', $household->id)->where('name', $name)->valueOrFail('id');

        foreach ([
            'Aluguel' => 280000, 'Condomínio' => 65000, 'Luz' => 20000, 'Internet' => 12000,
            'Mercado' => 150000, 'Restaurante' => 40000, 'Combustível' => 25000, 'Farmácia' => 8000,
            'Assinaturas' => 6000, 'Lazer' => 30000,
        ] as $name => $amount) {
            app(SaveBudget::class)->execute($household->id, $category($name), $first, $amount);
        }

        for ($month = $first->copy()->addMonthNoOverflow(); $month->lte(today()); $month->addMonthNoOverflow()) {
            app(CopyPreviousMonth::class)->execute($household->id, $month);
        }
    }

    private function attachments(User $eduardo): void
    {
        $previous = config('attachments.disk');
        config(['attachments.disk' => 'local']);

        $pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

        Transaction::where('description', 'Aluguel')->where('status', 'paid')->orderByDesc('date')->limit(2)->get()
            ->each(fn (Transaction $rent) => app(AttachFile::class)->execute($eduardo, $rent, $pdf, 'recibo-aluguel.pdf', 'application/pdf'));

        config(['attachments.disk' => $previous]);
    }

    /**
     * Corretora compartilhada com aporte da conta conjunta, ações, FII e ETF, uma venda parcial,
     * um desdobramento e cotações dos últimos dias úteis.
     */
    private function portfolio(User $eduardo, Account $joint): void
    {
        $broker = app(CreateAccount::class)->execute($eduardo, [
            'name' => 'XP Investimentos', 'type' => AccountType::Brokerage, 'visibility' => AccountVisibility::Shared,
            'currency' => 'BRL', 'initial_balance' => 0,
        ]);

        app(CreateTransfer::class)->execute($eduardo, [
            'from_account_id' => $joint->id, 'to_account_id' => $broker->id, 'amount' => 2000000,
            'date' => today()->subMonthsNoOverflow(5)->toDateString(), 'description' => 'Aporte na corretora',
        ]);

        $operations = app(ManageOperations::class);
        $day = fn (int $daysAgo): string => today()->subDays($daysAgo)->toDateString();
        $asset = fn (string $ticker, string $name, string $type) => app(SaveAsset::class)->execute($eduardo, [
            'account_id' => $broker->id, 'type' => $type, 'ticker' => $ticker, 'name' => $name,
        ]);

        $petr = $asset('PETR4', 'Petrobras PN', 'stock');
        $operations->register($eduardo, $petr, ['type' => 'buy', 'date' => $day(150), 'quantity' => '200', 'unit_price' => '36,20', 'fees' => 490]);
        $operations->register($eduardo, $petr, ['type' => 'buy', 'date' => $day(90), 'quantity' => '100', 'unit_price' => '38,90', 'fees' => 250]);
        $operations->register($eduardo, $petr, ['type' => 'sell', 'date' => $day(30), 'quantity' => '120', 'unit_price' => '41,15', 'fees' => 310]);

        $bbas = $asset('BBAS3', 'Banco do Brasil ON', 'stock');
        $operations->register($eduardo, $bbas, ['type' => 'buy', 'date' => $day(140), 'quantity' => '100', 'unit_price' => '54,80', 'fees' => 300]);
        $operations->register($eduardo, $bbas, ['type' => 'split', 'date' => $day(100), 'factor' => '2']);

        $hglg = $asset('HGLG11', 'CSHG Logística FII', 'fii');
        $operations->register($eduardo, $hglg, ['type' => 'buy', 'date' => $day(120), 'quantity' => '20', 'unit_price' => '158,40', 'fees' => 120]);
        $operations->register($eduardo, $hglg, ['type' => 'buy', 'date' => $day(60), 'quantity' => '15', 'unit_price' => '161,10', 'fees' => 90]);

        $bova = $asset('BOVA11', 'iShares Ibovespa ETF', 'etf');
        $operations->register($eduardo, $bova, ['type' => 'buy', 'date' => $day(80), 'quantity' => '30', 'unit_price' => '124,50', 'fees' => 150]);

        // Cotações fictícias dos últimos 5 dias úteis (em produção vêm da brapi).
        $base = ['PETR4' => 39.80, 'BBAS3' => 28.10, 'HGLG11' => 163.25, 'BOVA11' => 131.40];
        $assets = ['PETR4' => $petr, 'BBAS3' => $bbas, 'HGLG11' => $hglg, 'BOVA11' => $bova];

        for ($i = 0, $date = today()->subDay(); $i < 5; $date->subDay()) {
            if ($date->isWeekend()) {
                continue;
            }

            foreach ($assets as $ticker => $model) {
                $price = new AssetPrice(['asset_id' => $model->id, 'date' => $date->copy(), 'source' => PriceSource::Api]);
                $price->household_id = $model->household_id;
                $price->price = number_format($base[$ticker] * (1 - $i * 0.004), 2, '.', '');
                $price->save();
            }

            $i++;
        }

        $this->portfolioHistory($eduardo, $assets, $base);
    }

    /**
     * Cotações de fim de mês (6 meses), proventos e série do CDI fictícios, para a rentabilidade.
     *
     * @param  array<string, Asset>  $assets
     * @param  array<string, float>  $base
     */
    private function portfolioHistory(User $eduardo, array $assets, array $base): void
    {
        // BBAS3 teve desdobramento 1→2 há 100 dias: antes disso a cotação era o dobro.
        $splitDate = today()->subDays(100);

        for ($monthsAgo = 6; $monthsAgo >= 1; $monthsAgo--) {
            $date = today()->startOfMonth()->subMonthsNoOverflow($monthsAgo)->endOfMonth()->startOfDay();
            $drift = 1 - $monthsAgo * 0.015;

            foreach ($assets as $ticker => $asset) {
                $value = $base[$ticker] * $drift * ($ticker === 'BBAS3' && $date->lt($splitDate) ? 2 : 1);
                $price = new AssetPrice(['asset_id' => $asset->id, 'date' => $date, 'source' => PriceSource::Api]);
                $price->household_id = $asset->household_id;
                $price->price = number_format($value, 2, '.', '');
                $price->save();
            }
        }

        $incomes = app(ManageIncomes::class);
        $incomes->register($eduardo, $assets['PETR4'], ['type' => 'dividend', 'date' => today()->subDays(75)->toDateString(), 'gross_amount' => 21400]);
        $incomes->register($eduardo, $assets['BBAS3'], ['type' => 'jcp', 'date' => today()->subDays(45)->toDateString(), 'gross_amount' => 9800, 'withheld_tax' => 1470]);

        for ($monthsAgo = 3; $monthsAgo >= 0; $monthsAgo--) {
            $payment = today()->startOfMonth()->subMonthsNoOverflow($monthsAgo)->addDays(14);

            if ($payment->lte(today())) {
                $incomes->register($eduardo, $assets['HGLG11'], ['type' => 'fund_income', 'date' => $payment->toDateString(), 'gross_amount' => $monthsAgo >= 2 ? 2200 : 3850]);
            }
        }

        // CDI fictício (~14% ao ano); em produção vem do Banco Central (app:fetch-cdi).
        for ($date = today()->subMonthsNoOverflow(7); $date->lte(today()); $date->addDay()) {
            if (! $date->isWeekend()) {
                InterestRate::updateOrCreate(['series' => InterestRate::CDI, 'date' => $date->toDateString()], ['rate' => '0.05213']);
            }
        }
    }

    /**
     * CDB 110% do CDI e Tesouro IPCA+ na corretora, com saldos mensais; VGBL debitado da conta do Eduardo
     * com o último saldo há 40 dias (aparece o lembrete de atualizar o saldo).
     */
    private function fixedIncome(User $eduardo): void
    {
        $broker = Account::where('name', 'XP Investimentos')->sole();
        $checking = Account::where('name', 'Nubank Eduardo')->sole();
        $operations = app(ManageOperations::class);
        $valuations = app(ManageValuations::class);
        $asset = fn (Account $account, string $type, string $name, array $details = []) => app(SaveAsset::class)->execute($eduardo, [
            'account_id' => $account->id, 'type' => $type, 'name' => $name, ...$details,
        ]);
        $monthsAgo = fn (int $months, int $day = 5): Carbon => today()->startOfMonth()->subMonthsNoOverflow($months)->day($day);

        $cdb = $asset($broker, 'fixed_income', 'CDB Banco Inter 2028', ['issuer' => 'Banco Inter', 'indexer' => 'cdi', 'rate' => '110% do CDI', 'maturity_date' => today()->addYears(2)->toDateString()]);
        $operations->register($eduardo, $cdb, ['type' => 'contribution', 'date' => $monthsAgo(5)->toDateString(), 'amount' => 1000000]);
        $operations->register($eduardo, $cdb, ['type' => 'withdrawal', 'date' => $monthsAgo(2, 12)->toDateString(), 'amount' => 200000]);
        $balance = 1000000;

        for ($months = 4; $months >= 0; $months--) {
            $date = $months === 0 ? today()->subDays(5) : $monthsAgo($months, 28);
            $balance = (int) round($balance * 1.0115) - ($months === 1 ? 200000 : 0);
            $valuations->save($eduardo, $cdb, $date, $balance);
        }

        $tesouro = $asset($broker, 'fixed_income', 'Tesouro IPCA+ 2035', ['issuer' => 'Tesouro Nacional', 'indexer' => 'ipca', 'rate' => 'IPCA + 6,8%', 'maturity_date' => '2035-05-15']);
        $operations->register($eduardo, $tesouro, ['type' => 'contribution', 'date' => $monthsAgo(4)->toDateString(), 'amount' => 500000]);
        $valuations->save($eduardo, $tesouro, $monthsAgo(2, 28), 509800);
        $valuations->save($eduardo, $tesouro, today()->subDays(10), 521450);

        $vgbl = $asset($checking, 'pension', 'VGBL Brasilprev', ['issuer' => 'Brasilprev', 'rate' => 'Fundo multimercado']);

        for ($months = 5; $months >= 0; $months--) {
            if ($monthsAgo($months, 10)->lte(today())) {
                $operations->register($eduardo, $vgbl, ['type' => 'contribution', 'date' => $monthsAgo($months, 10)->toDateString(), 'amount' => 50000]);
            }
        }

        // Saldo = aportes feitos até a data + um pouco de rendimento.
        $invested = 50000 * $vgbl->operations()->whereDate('date', '<=', today()->subDays(40))->count();
        $valuations->save($eduardo, $vgbl, today()->subDays(40), (int) round($invested * 1.024));
    }

    /**
     * Corretora em dólar (Avenue) com AAPL, VOO e um REIT; câmbio e cotações fictícios
     * (em produção vêm da PTAX/Banco Central e da Finnhub).
     */
    private function foreign(User $eduardo): void
    {
        // Câmbio diário fictício dos últimos 7 meses, de R$ 5,05 a R$ 5,45.
        $start = today()->subMonthsNoOverflow(7);
        $days = (int) $start->diffInDays(today());

        for ($date = $start->copy(), $i = 0; $date->lte(today()); $date->addDay(), $i++) {
            if (! $date->isWeekend()) {
                ExchangeRate::updateOrCreate(
                    ['currency' => 'USD', 'date' => $date->toDateString()],
                    ['rate' => number_format(5.05 + 0.40 * $i / max(1, $days) + 0.03 * sin($i / 6), 4, '.', '')],
                );
            }
        }

        $avenue = app(CreateAccount::class)->execute($eduardo, [
            'name' => 'Avenue (USD)', 'type' => AccountType::Brokerage, 'visibility' => AccountVisibility::Shared,
            'currency' => 'USD', 'initial_balance' => 600000,
        ]);

        $operations = app(ManageOperations::class);
        $day = fn (int $daysAgo): string => today()->subDays($daysAgo)->toDateString();
        $asset = fn (string $ticker, string $name, string $type) => app(SaveAsset::class)->execute($eduardo, [
            'account_id' => $avenue->id, 'type' => $type, 'ticker' => $ticker, 'name' => $name,
        ]);

        $aapl = $asset('AAPL', 'Apple Inc.', 'stock');
        $operations->register($eduardo, $aapl, ['type' => 'buy', 'date' => $day(190), 'quantity' => '8', 'unit_price' => '195.40', 'fees' => 0]);
        $operations->register($eduardo, $aapl, ['type' => 'buy', 'date' => $day(70), 'quantity' => '4', 'unit_price' => '214.10', 'fees' => 0]);

        $voo = $asset('VOO', 'Vanguard S&P 500 ETF', 'etf');
        $operations->register($eduardo, $voo, ['type' => 'buy', 'date' => $day(160), 'quantity' => '3', 'unit_price' => '498.30', 'fees' => 0]);

        $realty = $asset('O', 'Realty Income', 'reit');
        $operations->register($eduardo, $realty, ['type' => 'buy', 'date' => $day(120), 'quantity' => '15', 'unit_price' => '56.20', 'fees' => 0]);
        app(ManageIncomes::class)->register($eduardo, $realty, ['type' => 'dividend', 'date' => $day(20), 'gross_amount' => 399, 'withheld_tax' => 120]);

        foreach (['AAPL' => [$aapl, 228.35], 'VOO' => [$voo, 541.20], 'O' => [$realty, 58.90]] as [$model, $price]) {
            foreach ([1, 2, 3] as $daysAgo) {
                $record = new AssetPrice(['asset_id' => $model->id, 'date' => today()->subDays($daysAgo), 'source' => PriceSource::Api]);
                $record->household_id = $model->household_id;
                $record->price = number_format($price * (1 - $daysAgo * 0.003), 2, '.', '');
                $record->save();
            }
        }
    }

    /**
     * Financiamento SAC na conta conjunta (parcelas pagas até hoje e uma amortização
     * extraordinária no meio) e empréstimo pessoal Price na conta do Eduardo.
     */
    private function debts(Household $household, User $eduardo): void
    {
        $debts = app(ManageDebts::class);
        $joint = Account::where('name', 'Conta conjunta')->sole();
        $personal = Account::where('name', 'Nubank Eduardo')->sole();
        $category = fn (string $name): int => Category::where('household_id', $household->id)->where('name', $name)->valueOrFail('id');

        $payUntil = function (Debt $debt, Carbon $until) use ($eduardo): void {
            app(GenerateDebtInstallments::class)->execute($debt);

            DebtInstallment::where('debt_id', $debt->id)->whereNotNull('transaction_id')->whereDate('due_date', '<=', $until)->orderBy('number')->get()
                ->each(function (DebtInstallment $installment) use ($eduardo): void {
                    $transaction = Transaction::findOrFail($installment->transaction_id);

                    if ($transaction->status === TransactionStatus::Scheduled) {
                        app(MarkAsPaid::class)->execute($eduardo, $transaction, $installment->due_date);
                    }
                });
        };

        $mortgage = $debts->create($eduardo, [
            'name' => 'Financiamento do apartamento', 'creditor' => 'Caixa Econômica Federal', 'principal' => 32000000,
            'monthly_rate' => '0,79', 'system' => 'sac', 'installments_count' => 360,
            'first_due_date' => today()->startOfMonth()->subMonthsNoOverflow(8)->day(20)->toDateString(),
            'payment_account_id' => $joint->id, 'category_id' => $category('Moradia'),
        ]);
        $payUntil($mortgage, today()->startOfMonth()->subMonthsNoOverflow(4)->day(20));
        $debts->prepay($eduardo, $mortgage, today()->startOfMonth()->subMonthsNoOverflow(4)->day(25), 2000000, PrepaymentMode::ReduceTerm);
        $payUntil($mortgage, today());

        $loan = $debts->create($eduardo, [
            'name' => 'Empréstimo pessoal', 'creditor' => 'Banco Inter', 'principal' => 1500000,
            'monthly_rate' => '1,89', 'system' => 'price', 'installments_count' => 18,
            'first_due_date' => today()->startOfMonth()->subMonthsNoOverflow(3)->day(8)->toDateString(),
            'payment_account_id' => $personal->id, 'category_id' => $category('Outras despesas'),
        ]);
        $payUntil($loan, today());
    }

    /**
     * Reserva de emergência (poupança + CDB, no ritmo), viagem (dinheiro da carteira, atrasada)
     * e troca do celular da Maria (pessoal, já atingida).
     */
    private function goals(User $eduardo, User $maria): void
    {
        $goals = app(SaveGoal::class);
        $account = fn (string $name): int => Account::where('name', $name)->valueOrFail('id');

        $goals->execute($eduardo, [
            'name' => 'Reserva de emergência', 'target' => 6000000, 'visibility' => AccountVisibility::Shared,
            'start_date' => today()->subMonthsNoOverflow(6)->toDateString(), 'deadline' => today()->addMonthsNoOverflow(12)->endOfMonth()->toDateString(),
            'account_ids' => [$account('Poupança')], 'asset_ids' => [Asset::where('name', 'CDB Banco Inter 2028')->valueOrFail('id')],
        ]);

        $goals->execute($eduardo, [
            'name' => 'Viagem de fim de ano', 'target' => 800000, 'visibility' => AccountVisibility::Shared,
            'start_date' => today()->subMonthsNoOverflow(3)->toDateString(), 'deadline' => today()->addMonthsNoOverflow(2)->endOfMonth()->toDateString(),
            'account_ids' => [$account('Carteira')],
        ]);

        $goals->execute($maria, [
            'name' => 'Troca do celular', 'target' => 300000, 'visibility' => AccountVisibility::Private,
            'start_date' => today()->subMonthsNoOverflow(4)->toDateString(), 'deadline' => today()->addMonthNoOverflow()->endOfMonth()->toDateString(),
            'account_ids' => [$account('Itaú Maria')],
        ]);
    }

    private function recurrence(Household $household, User $payer, Account $account, string $category, int $amount, string $frequency, ?int $day, Carbon $start, bool $estimate = false, ?string $description = null): void
    {
        app(CreateRecurrence::class)->execute($payer, [
            'account_id' => $account->id,
            'category_id' => Category::where('household_id', $household->id)->where('name', $category)->valueOrFail('id'),
            'amount' => $amount,
            'amount_is_estimate' => $estimate,
            'description' => $description ?? $category,
            'paid_by' => $payer->id,
            'frequency' => $frequency,
            'day_of_month' => $day,
            'start_date' => $start->toDateString(),
        ]);
    }

    private function transfer(User $actor, Account $from, Account $to, int $day, int $amount, string $description, Carbon $month): void
    {
        $date = $month->copy()->day(min($day, $month->daysInMonth));

        if ($date->isFuture()) {
            return;
        }

        app(CreateTransfer::class)->execute($actor, [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => $amount,
            'date' => $date->toDateString(),
            'description' => $description,
        ]);
    }

    /**
     * Lançamentos do mês corrente com data futura entram como previstos.
     *
     * @param  list<string>  $tags
     */
    private function add(Household $household, User $payer, Account $account, string $category, int $day, int $amount, string $description, Carbon $month, array $tags = []): void
    {
        $date = $month->copy()->day(min($day, $month->daysInMonth));

        app(CreateTransaction::class)->execute($payer, [
            'status' => $date->isFuture() ? 'scheduled' : 'paid',
            'due_date' => $date->isFuture() ? $date->toDateString() : null,
            'account_id' => $account->id,
            'category_id' => Category::where('household_id', $household->id)->where('name', $category)->valueOrFail('id'),
            'amount' => $amount,
            'date' => $date->toDateString(),
            'description' => $description,
            'paid_by' => $payer->id,
            'tags' => $tags,
        ]);
    }
}
