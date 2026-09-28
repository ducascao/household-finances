<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Lançamentos já existentes são pagos.
            $table->string('status', 20)->default('paid')->after('amount');
            $table->date('due_date')->nullable()->after('date');
            $table->uuid('transfer_id')->nullable()->after('household_id');
            $table->foreignId('category_id')->nullable()->change();

            $table->index('transfer_id');
            $table->index(['household_id', 'status', 'due_date']);
        });

        // Transferência não tem categoria; receita e despesa sempre têm. Previsto sempre tem vencimento.
        DB::statement('alter table transactions add constraint transactions_category_or_transfer check ((transfer_id is null) = (category_id is not null))');
        DB::statement("alter table transactions add constraint transactions_scheduled_due_date check (status <> 'scheduled' or due_date is not null)");
    }

    public function down(): void
    {
        DB::statement('alter table transactions drop constraint transactions_scheduled_due_date');
        DB::statement('alter table transactions drop constraint transactions_category_or_transfer');

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['household_id', 'status', 'due_date']);
            $table->dropIndex(['transfer_id']);
            $table->foreignId('category_id')->nullable(false)->change();
            $table->dropColumn(['status', 'due_date', 'transfer_id']);
        });
    }
};
