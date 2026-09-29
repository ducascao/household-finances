<?php

namespace App\Console\Commands;

use App\Domain\Investments\Cdi;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class FetchCdiCommand extends Command
{
    protected $signature = 'app:fetch-cdi {--from= : Data inicial AAAA-MM-DD (padrão: 30 dias atrás)}';

    protected $description = 'Carrega a série diária do CDI do Banco Central (para comparar a rentabilidade)';

    public function handle(Cdi $cdi): int
    {
        $from = $this->option('from') ? Carbon::parse((string) $this->option('from')) : today()->subDays(30);
        $stored = $cdi->fetch($from, today());

        $this->info("{$stored} taxa(s) do CDI gravada(s) desde {$from->format('d/m/Y')}.");

        return self::SUCCESS;
    }
}
