<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('creditor');
            $table->bigInteger('principal');
            // % ao mês (ex.: 0.95000000 = 0,95% a.m.).
            $table->decimal('monthly_rate', 12, 8);
            $table->string('system', 20);
            $table->unsignedSmallInteger('installments_count');
            $table->date('first_due_date');
            $table->foreignId('payment_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('transactions', function (Blueprint $table) {
            // Parcelas e amortizações extraordinárias: editadas só pela dívida.
            $table->foreignId('debt_id')->nullable()->after('asset_income_id')->constrained()->restrictOnDelete();
        });

        Schema::create('debt_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('debt_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->date('due_date');
            $table->bigInteger('amortization');
            $table->bigInteger('interest');
            $table->bigInteger('total');
            $table->bigInteger('balance_after');
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['debt_id', 'number']);
            $table->index(['debt_id', 'due_date']);
        });

        Schema::create('debt_prepayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('debt_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->bigInteger('amount');
            $table->string('mode', 30);
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_prepayments');
        Schema::dropIfExists('debt_installments');

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('debt_id');
        });

        Schema::dropIfExists('debts');
    }
};
