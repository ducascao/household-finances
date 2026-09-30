<?php

namespace App\Jobs;

use App\Domain\Debts\GenerateDebtInstallments;
use App\Domain\Recurrences\GenerateOccurrences;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Gera diariamente os lançamentos previstos das recorrências e das parcelas de dívidas até hoje + 60 dias.
 */
class GenerateRecurringTransactions implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function handle(GenerateOccurrences $generateOccurrences, GenerateDebtInstallments $debts): void
    {
        $created = $generateOccurrences->executeAll() + $debts->executeAll();

        Log::info("Recorrências: {$created} lançamento(s) previsto(s) gerado(s).");
    }
}
