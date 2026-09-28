<?php

namespace App\Console\Commands;

use App\Domain\Recurrences\GenerateOccurrences;
use Illuminate\Console\Command;

class GenerateRecurrencesCommand extends Command
{
    protected $signature = 'app:generate-recurrences';

    protected $description = 'Gera agora os lançamentos previstos das recorrências (até hoje + 60 dias)';

    public function handle(GenerateOccurrences $generateOccurrences): int
    {
        $created = $generateOccurrences->executeAll();

        $this->info("{$created} lançamento(s) previsto(s) gerado(s).");

        return self::SUCCESS;
    }
}
