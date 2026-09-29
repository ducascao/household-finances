<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->string('ticker', 20);
            $table->string('name');
            $table->char('currency', 3)->default('BRL');
            $table->timestamps();

            $table->unique(['account_id', 'ticker']);
        });

        Schema::create('asset_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->date('date');
            $table->decimal('quantity', 20, 8)->nullable();
            $table->decimal('unit_price', 20, 8)->nullable();
            $table->bigInteger('fees')->default(0);
            // Desdobramento 1→N e grupamento N→1.
            $table->decimal('factor', 20, 8)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'date']);
        });

        Schema::create('asset_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('price', 20, 8);
            $table->string('source', 20);
            $table->timestamps();

            $table->unique(['asset_id', 'date', 'source']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('asset_operation_id')->nullable()->after('installment_number')->constrained()->restrictOnDelete();
        });

        // Sem categoria: transferências e compras/vendas de ativos. Com categoria: receitas e despesas.
        DB::statement('alter table transactions drop constraint transactions_category_or_transfer');
        DB::statement('alter table transactions add constraint transactions_category_or_transfer check ((transfer_id is null and asset_operation_id is null) = (category_id is not null))');
    }

    public function down(): void
    {
        DB::statement('alter table transactions drop constraint transactions_category_or_transfer');
        DB::statement('alter table transactions add constraint transactions_category_or_transfer check ((transfer_id is null) = (category_id is not null))');

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('asset_operation_id');
        });

        Schema::dropIfExists('asset_prices');
        Schema::dropIfExists('asset_operations');
        Schema::dropIfExists('assets');
    }
};
