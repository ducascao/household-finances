<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            // Renda fixa e previdência não têm ticker.
            $table->string('ticker', 20)->nullable()->change();
            $table->string('issuer')->nullable()->after('name');
            $table->string('indexer', 20)->nullable()->after('issuer');
            $table->string('rate', 60)->nullable()->after('indexer');
            $table->date('maturity_date')->nullable()->after('rate');
        });

        Schema::table('asset_operations', function (Blueprint $table) {
            // Aporte e resgate são por valor (centavos).
            $table->bigInteger('amount')->nullable()->after('factor');
        });

        Schema::create('manual_valuations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->bigInteger('balance');
            $table->timestamps();

            $table->unique(['asset_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_valuations');

        Schema::table('asset_operations', function (Blueprint $table) {
            $table->dropColumn('amount');
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['issuer', 'indexer', 'rate', 'maturity_date']);
            $table->string('ticker', 20)->nullable(false)->change();
        });
    }
};
