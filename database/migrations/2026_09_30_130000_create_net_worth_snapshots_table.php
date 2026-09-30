<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fotografia mensal do patrimônio (centavos, em reais). user_id = visão de uma pessoa; vazio = visão do lar.
        Schema::create('net_worth_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->bigInteger('accounts');
            $table->bigInteger('investments');
            $table->bigInteger('debts');
            $table->bigInteger('net_worth');
            $table->timestamps();

            $table->unique(['household_id', 'user_id', 'month'])->nullsNotDistinct();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('net_worth_snapshots');
    }
};
