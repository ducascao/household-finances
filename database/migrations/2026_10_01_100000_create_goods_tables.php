<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('type', 20);
            $table->string('visibility', 20);
            $table->date('acquisition_date');
            $table->bigInteger('acquisition_value');
            $table->date('sale_date')->nullable();
            $table->bigInteger('sale_value')->nullable();
            // Financiamento do bem (opcional): permite mostrar o valor líquido.
            $table->foreignId('debt_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('good_valuations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('good_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->bigInteger('value');
            $table->timestamps();

            $table->unique(['good_id', 'date']);
        });

        Schema::table('net_worth_snapshots', function (Blueprint $table) {
            $table->bigInteger('goods')->default(0)->after('investments');
        });
    }

    public function down(): void
    {
        Schema::table('net_worth_snapshots', function (Blueprint $table) {
            $table->dropColumn('goods');
        });

        Schema::dropIfExists('good_valuations');
        Schema::dropIfExists('goods');
    }
};
