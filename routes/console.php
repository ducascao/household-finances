<?php

use App\Jobs\FetchCdi;
use App\Jobs\FetchExchangeRates;
use App\Jobs\FetchQuotes;
use App\Jobs\FetchTr;
use App\Jobs\GenerateRecurringTransactions;
use App\Jobs\TakeNetWorthSnapshots;
use Illuminate\Support\Facades\Schedule;

// Contas fixas: gera os previstos até hoje + 60 dias.
Schedule::job(new GenerateRecurringTransactions)->dailyAt('01:00')->withoutOverlapping();

// Cópia do backup do Postgres (feito às 03:00 pelo container backup) para o Google Drive.
Schedule::command('app:backup-to-drive')->dailyAt('04:00')->withoutOverlapping();

// Cotações da carteira: dias úteis, depois do fechamento da B3.
Schedule::job(new FetchQuotes)->weekdays()->at('19:00')->withoutOverlapping();

// CDI diário do Banco Central (para comparar a rentabilidade).
Schedule::job(new FetchCdi)->dailyAt('09:00')->withoutOverlapping();

// TR do Banco Central (financiamentos corrigidos pela TR): recalcula as parcelas futuras.
Schedule::job(new FetchTr)->dailyAt('09:10')->withoutOverlapping();

// Câmbio PTAX (fechamento publicado por volta das 13h), dias úteis.
Schedule::job(new FetchExchangeRates)->weekdays()->at('13:30')->withoutOverlapping();

// Patrimônio líquido: fotografia no último dia do mês.
Schedule::job(new TakeNetWorthSnapshots)->lastDayOfMonth('23:30')->withoutOverlapping();
