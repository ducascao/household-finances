<?php

namespace Database\Seeders;

use App\Domain\Accounts\CreateAccount;
use App\Domain\Household\AddMember;
use App\Domain\Household\CreateHousehold;
use App\Domain\Recurrences\CreateRecurrence;
use App\Domain\Transactions\CreateTransaction;
use App\Domain\Transfers\CreateTransfer;
use App\Enums\AccountType;
use App\Enums\AccountVisibility;
use App\Models\Account;
use App\Models\Category;
use App\Models\Household;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Lar de exemplo com 2 usuários, contas pessoais e compartilhadas, 3 meses de lançamentos,
 * contas previstas (atrasadas e a vencer), transferências e contas fixas (recorrências).
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
