<?php

namespace App\Jobs;

use App\Domain\Debts\ManageDebts;
use App\Domain\Debts\ReferenceRate;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Atualiza a TR dos últimos 45 dias e recalcula as parcelas ainda não pagas das dívidas corrigidas pela TR.
 */
class FetchTr implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function handle(ReferenceRate $tr, ManageDebts $debts): void
    {
        $tr->fetch(today()->subDays(45), today());
        $debts->refreshTrDebts();
    }
}
