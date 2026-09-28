<?php

namespace Database\Seeders;

use App\Domain\Accounts\CreateAccount;
use App\Domain\CreditCard\CreateInstallmentPurchase;
use App\Domain\CreditCard\PayInvoice;
use App\Domain\Household\AddMember;
use App\Domain\Household\CreateHousehold;
use App\Domain\Import\BankPresets;
use App\Domain\Import\ImportStatement;
use App\Domain\Import\SaveImportProfile;
use App\Domain\Import\SaveImportRule;
use App\Domain\Recurrences\CreateRecurrence;
use App\Domain\Transactions\CreateTransaction;
use App\Domain\Transfers\CreateTransfer;
use App\Enums\AccountType;
use App\Enums\AccountVisibility;
use App\Enums\ImportFormat;
use App\Models\Account;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\Household;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Lar de exemplo com 2 usuários, contas pessoais e compartilhadas, 3 meses de lançamentos,
 * contas previstas (atrasadas e a vencer), transferências, contas fixas, cartões de crédito com faturas
 * e importação (regras, perfil de CSV e um lote em revisão).
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
        $joint = $account($eduardo, 'Conta conjunta', AccountType::Checking, AccountVisibility::Shared, 500000);
        $wallet = $account($maria, 'Carteira', AccountType::Cash, AccountVisibility::Shared, 20000);
        $savings = $account($eduardo, 'Poupança', AccountType::Savings, AccountVisibility::Shared, 1500000);

        for ($monthsAgo = 2; $monthsAgo >= 0; $monthsAgo--) {
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
            $this->transfer($eduardo, $nubank, $joint, 6, 200000, 'Aporte na conjunta', $month);
            $this->transfer($maria, $itau, $joint, 6, 200000, 'Aporte na conjunta', $month);
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
