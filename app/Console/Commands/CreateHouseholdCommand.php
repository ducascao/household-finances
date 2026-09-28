<?php

namespace App\Console\Commands;

use App\Domain\Household\CreateHousehold;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateHouseholdCommand extends Command
{
    protected $signature = 'app:create-household';

    protected $description = 'Cria um lar e o seu usuário administrador';

    public function handle(CreateHousehold $createHousehold): int
    {
        $householdName = text('Nome do lar', required: true);
        $name = text('Nome do administrador', required: true);
        $email = text('E-mail do administrador', required: true);
        $password = password('Senha (mínimo 8 caracteres)', required: true);

        try {
            $household = $createHousehold->execute($householdName, $name, $email, $password);
        } catch (ValidationException $e) {
            foreach ($e->validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $this->info("Lar \"{$household->name}\" criado com o administrador {$email}.");
        $this->line('No primeiro login será pedido o cadastro da autenticação em dois fatores.');

        return self::SUCCESS;
    }
}
