<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_incomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            // Data de pagamento.
            $table->date('date');
            $table->bigInteger('gross_amount');
            $table->bigInteger('withheld_tax')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'date']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('asset_income_id')->nullable()->after('asset_operation_id')->constrained()->restrictOnDelete();
        });

        // Subcategorias de proventos em "Rendimentos" para os lares que já existem.
        $now = now();

        foreach (DB::table('categories')->whereNull('parent_id')->where('type', 'income')->where('name', 'Rendimentos')->get() as $root) {
            foreach (['Dividendos', 'JCP', 'Rendimentos de FII'] as $name) {
                $exists = DB::table('categories')->where('parent_id', $root->id)->where('name', $name)->exists();

                if (! $exists) {
                    DB::table('categories')->insert([
                        'household_id' => $root->household_id,
                        'parent_id' => $root->id,
                        'name' => $name,
                        'type' => 'income',
                        'color' => $root->color,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('asset_income_id');
        });

        Schema::dropIfExists('asset_incomes');
    }
};
