<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Em produção, crie o lar com app:create-household; os dados de exemplo são só para desenvolvimento.
     */
    public function run(): void
    {
        if (! app()->isProduction()) {
            $this->call(DemoSeeder::class);
        }
    }
}
