<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Séries públicas de juros (ex.: CDI). Sem household_id de propósito: é dado de mercado,
     * igual para todos os lares (exceção registrada no PLAN, E10).
     */
    public function up(): void
    {
        Schema::create('interest_rates', function (Blueprint $table) {
            $table->id();
            $table->string('series', 20);
            $table->date('date');
            // Taxa do dia em % (ex.: 0.05392800 = 0,053928% ao dia).
            $table->decimal('rate', 12, 8);
            $table->timestamps();

            $table->unique(['series', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interest_rates');
    }
};
