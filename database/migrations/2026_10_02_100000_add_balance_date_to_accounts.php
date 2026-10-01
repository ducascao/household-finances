<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // Saldo inicial vale no fim deste dia: lançamentos pagos até ele não entram no saldo.
            $table->date('balance_date')->nullable()->after('initial_balance');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('balance_date');
        });
    }
};
