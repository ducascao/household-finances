<?php

namespace App\Jobs;

use App\Domain\Investments\Cdi;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Atualiza diariamente o CDI dos últimos 30 dias (cobre feriados e atrasos da divulgação).
 */
class FetchCdi implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function handle(Cdi $cdi): void
    {
        $cdi->fetch(today()->subDays(30), today());
    }
}
