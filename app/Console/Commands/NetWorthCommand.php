<?php

namespace App\Console\Commands;

use App\Domain\NetWorth\TakeSnapshots;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class NetWorthCommand extends Command
{
    protected $signature = 'app:net-worth {--month= : Mês AAAA-MM (padrão: o corrente)} {--from= : Recalcula de AAAA-MM até o mês corrente}';

    protected $description = 'Grava ou recalcula as fotografias mensais do patrimônio';

    public function handle(TakeSnapshots $snapshots): int
    {
        $first = Carbon::createFromFormat('!Y-m', (string) ($this->option('from') ?? $this->option('month') ?? today()->format('Y-m')));

        if ($first === null) {
            $this->error('Informe o mês no formato AAAA-MM.');

            return self::FAILURE;
        }

        $last = $this->option('from') !== null ? today()->startOfMonth() : $first->copy();
        $total = 0;

        for ($month = $first->copy(); $month->lte($last); $month->addMonthNoOverflow()) {
            $total += $snapshots->forMonth($month);
        }

        $this->info("{$total} fotografia(s) gravada(s) de {$first->format('m/Y')} a {$last->format('m/Y')}.");

        return self::SUCCESS;
    }
}
