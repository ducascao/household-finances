<?php

namespace App\Domain\Household;

use App\Domain\Categories\CreateDefaultCategories;
use App\Enums\HouseholdRole;
use App\Models\Household;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CreateHousehold
{
    public function __construct(
        private readonly AddMember $addMember,
        private readonly CreateDefaultCategories $createDefaultCategories,
    ) {}

    /**
     * Cria o lar com seu primeiro usuário, que fica como administrador,
     * e as categorias padrão.
     */
    public function execute(string $householdName, string $adminName, string $adminEmail, string $adminPassword): Household
    {
        Validator::make(
            ['household_name' => $householdName],
            ['household_name' => ['required', 'string', 'max:255']],
        )->validate();

        return DB::transaction(function () use ($householdName, $adminName, $adminEmail, $adminPassword): Household {
            $household = Household::create(['name' => $householdName]);

            $this->addMember->execute($household, $adminName, $adminEmail, $adminPassword, HouseholdRole::Admin);
            $this->createDefaultCategories->execute($household);

            return $household;
        });
    }
}
