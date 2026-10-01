<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debts', function (Blueprint $table) {
            // Saldo corrigido pela TR em cada aniversário (financiamento imobiliário).
            $table->boolean('tr_correction')->default(false)->after('system');
            // Seguro proporcional ao saldo (MIP), em % ao mês sobre o saldo corrigido.
            $table->decimal('insurance_rate', 12, 8)->default(0)->after('tr_correction');
            // Encargos fixos por parcela (seguro do imóvel, taxa de administração), em centavos.
            $table->bigInteger('monthly_fee')->default(0)->after('insurance_rate');
        });

        Schema::table('debt_installments', function (Blueprint $table) {
            $table->bigInteger('correction')->default(0)->after('due_date');
            $table->bigInteger('charges')->default(0)->after('interest');
        });

        Schema::create('debt_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('debt_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            // Diferença somada ao saldo devedor (saldo informado − saldo calculado).
            $table->bigInteger('amount');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_adjustments');

        Schema::table('debt_installments', function (Blueprint $table) {
            $table->dropColumn(['correction', 'charges']);
        });

        Schema::table('debts', function (Blueprint $table) {
            $table->dropColumn(['tr_correction', 'insurance_rate', 'monthly_fee']);
        });
    }
};
