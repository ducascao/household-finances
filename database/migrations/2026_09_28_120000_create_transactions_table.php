<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount');
            // Cópia da moeda da conta, para o cast de Money não depender da relação.
            $table->char('currency', 3);
            $table->date('date');
            $table->date('competence_date');
            $table->string('description');
            $table->foreignId('paid_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->jsonb('tags')->default('[]');
            $table->timestamps();

            $table->index(['household_id', 'date']);
            $table->index(['account_id', 'date']);
            $table->index(['household_id', 'competence_date']);
            $table->index('category_id');
            $table->index('tags', algorithm: 'gin');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
