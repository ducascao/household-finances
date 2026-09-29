<?php

use App\Jobs\GenerateRecurringTransactions;
use Illuminate\Support\Facades\Schedule;

// Contas fixas: gera os previstos até hoje + 60 dias.
Schedule::job(new GenerateRecurringTransactions)->dailyAt('01:00')->withoutOverlapping();

// Cópia do backup do Postgres (feito às 03:00 pelo container backup) para o Google Drive.
Schedule::command('app:backup-to-drive')->dailyAt('04:00')->withoutOverlapping();
