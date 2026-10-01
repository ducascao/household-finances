<?php

namespace App\Console\Commands;

use App\Domain\Debts\ManageDebts;
use App\Domain\Debts\ReferenceRate;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class FetchTrCommand extends Command
{
    protected $signature = 'app:fetch-tr {--from= : Data inicial AAAA-MM-DD (padrão: 45 dias atrás)}';

    protected $description = 'Carrega a TR do Banco Central e recalcula as parcelas futuras dos financiamentos corrigidos pela TR';

    public function handle(ReferenceRate $tr, ManageDebts $debts): int
    {
        $from = $this->option('from') ? Carbon::parse((string) $this->option('from')) : today()->subDays(45);
        $stored = $tr->fetch($from, today());
        $updated = $debts->refreshTrDebts();

        $this->info("{$stored} taxa(s) da TR gravada(s) desde {$from->format('d/m/Y')}; {$updated} dívida(s) recalculada(s).");

        return self::SUCCESS;
    }
}
