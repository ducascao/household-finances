<?php

use App\Jobs\GenerateRecurringTransactions;
use Illuminate\Support\Facades\Schedule;

// Contas fixas: gera os previstos até hoje + 60 dias.
Schedule::job(new GenerateRecurringTransactions)->dailyAt('01:00')->withoutOverlapping();
