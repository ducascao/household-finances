<?php

namespace App\Console\Commands;

use App\Domain\Household\AddMember;
use App\Enums\HouseholdRole;
use App\Models\Household;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class AddMemberCommand extends Command
{
    protected $signature = 'app:add-member';

    protected $description = 'Cria um usuário e o adiciona a um lar';

    public function handle(AddMember $addMember): int
    {
        $households = Household::query()->orderBy('name')->pluck('name', 'id');

        if ($households->isEmpty()) {
            $this->error('Nenhum lar cadastrado. Rode primeiro app:create-household.');

            return self::FAILURE;
        }

        $householdId = $households->count() === 1
            ? $households->keys()->first()
            : select('Lar', $households->all());

        $household = Household::findOrFail($householdId);

        $name = text('Nome', required: true);
        $email = text('E-mail', required: true);
        $password = password('Senha (mínimo 8 caracteres)', required: true);
        $role = select('Papel', HouseholdRole::options(), default: HouseholdRole::Member->value);

        try {
            $addMember->execute($household, $name, $email, $password, HouseholdRole::from((string) $role));
        } catch (ValidationException $e) {
            foreach ($e->validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $this->info("{$name} adicionado(a) ao lar \"{$household->name}\".");

        return self::SUCCESS;
    }
}
