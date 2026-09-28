<?php

namespace Database\Seeders;

use App\Domain\Accounts\CreateAccount;
use App\Domain\Household\AddMember;
use App\Domain\Household\CreateHousehold;
use App\Domain\Transactions\CreateTransaction;
use App\Enums\AccountType;
use App\Enums\AccountVisibility;
use App\Models\Account;
use App\Models\Category;
use App\Models\Household;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Lar de exemplo com 2 usuários, contas pessoais e compartilhadas e 3 meses de lançamentos.
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
        $account($eduardo, 'Poupança', AccountType::Savings, AccountVisibility::Shared, 1500000);

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
        }
    }

    /**
     * @param  list<string>  $tags
     */
    private function add(Household $household, User $payer, Account $account, string $category, int $day, int $amount, string $description, Carbon $month, array $tags = []): void
    {
        $date = $month->copy()->day(min($day, $month->daysInMonth));

        if ($date->isFuture()) {
            return;
        }

        app(CreateTransaction::class)->execute($payer, [
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
