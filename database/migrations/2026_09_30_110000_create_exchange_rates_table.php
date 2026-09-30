<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Câmbio diário (PTAX de venda do fechamento, Banco Central): reais por 1 unidade da moeda.
     * Sem household_id: dado público de mercado, igual para todos os lares (como o CDI).
     */
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->char('currency', 3);
            $table->date('date');
            $table->decimal('rate', 20, 8);
            $table->timestamps();

            $table->unique(['currency', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
