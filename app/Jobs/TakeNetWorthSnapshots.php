<?php

namespace App\Jobs;

use App\Domain\NetWorth\TakeSnapshots;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fotografia do patrimônio no último dia do mês.
 */
class TakeNetWorthSnapshots implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function handle(TakeSnapshots $snapshots): void
    {
        $snapshots->forMonth(today());
    }
}
