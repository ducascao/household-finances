<?php

namespace App\Console\Commands;

use App\Domain\Investments\PriceBook;
use Illuminate\Console\Command;

class FetchQuotesCommand extends Command
{
    protected $signature = 'app:fetch-quotes';

    protected $description = 'Busca agora as cotações dos ativos da carteira';

    public function handle(PriceBook $prices): int
    {
        $result = $prices->fetchAll();

        $this->info("{$result['updated']} cotação(ões) atualizada(s).");

        if ($result['missing'] !== []) {
            $this->warn('Sem cotação (mantida a última disponível): '.implode(', ', $result['missing']));
        }

        return self::SUCCESS;
    }
}
