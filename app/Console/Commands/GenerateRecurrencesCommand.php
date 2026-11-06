<?php

namespace App\Console\Commands;

use App\Domain\Debts\GenerateDebtInstallments;
use App\Domain\Recurrences\GenerateOccurrences;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GenerateRecurrencesCommand extends Command
{
    protected $signature = 'app:generate-recurrences
        {--until= : Gera até esta data (aaaa-mm-dd ou dd/mm/aaaa) em vez de hoje + 60 dias}';

    protected $description = 'Gera agora os lançamentos previstos das recorrências e das parcelas de dívidas (até hoje + 60 dias ou até --until)';

    public function handle(GenerateOccurrences $generateOccurrences, GenerateDebtInstallments $debts): int
    {
        $until = null;
        $option = $this->option('until');

        if (is_string($option) && $option !== '') {
            $until = $this->parseDate($option);

            if ($until === null) {
                $this->error("Data inválida em --until: \"{$option}\". Use aaaa-mm-dd ou dd/mm/aaaa.");

                return self::INVALID;
            }

            if ($until->lt(today())) {
                $this->warn('A data de --until já passou: só serão gerados os lançamentos atrasados que faltarem.');
            }
        }

        $created = $generateOccurrences->executeAll($until) + $debts->executeAll($until);

        $this->info("{$created} lançamento(s) previsto(s) gerado(s).");

        return self::SUCCESS;
    }

    private function parseDate(string $value): ?Carbon
    {
        foreach (['!Y-m-d', '!d/m/Y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);
            } catch (InvalidFormatException) {
                continue;
            }

            if ($date !== null && $date->format(ltrim($format, '!')) === $value) {
                return $date->startOfDay();
            }
        }

        return null;
    }
}
