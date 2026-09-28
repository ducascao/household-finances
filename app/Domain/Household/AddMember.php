<?php

namespace App\Domain\Household;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AddMember
{
    /**
     * Cria um usuário e o adiciona ao lar.
     */
    public function execute(
        Household $household,
        string $name,
        string $email,
        string $password,
        HouseholdRole $role = HouseholdRole::Member,
    ): User {
        Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:8'],
            ],
        )->validate();

        return DB::transaction(function () use ($household, $name, $email, $password, $role): User {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);

            $household->addUser($user, $role);

            return $user->refresh();
        });
    }
}
