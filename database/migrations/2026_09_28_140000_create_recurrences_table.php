<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->boolean('amount_is_estimate')->default(false);
            $table->string('description');
            $table->foreignId('paid_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->jsonb('tags')->default('[]');
            $table->string('frequency', 20);
            $table->unsignedSmallInteger('interval_months')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->date('start_date');
            // Próxima ocorrência ainda não gerada.
            $table->date('next_date');
            $table->date('end_date')->nullable();
            $table->timestamps();

            $table->index(['household_id', 'next_date']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('recurrence_id')->nullable()->after('transfer_id')
                ->constrained()->nullOnDelete();
            // Data da ocorrência na recorrência; não muda quando o vencimento é editado ou o lançamento é pago.
            $table->date('occurrence_date')->nullable()->after('due_date');

            $table->unique(['recurrence_id', 'occurrence_date']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['recurrence_id', 'occurrence_date']);
            $table->dropConstrainedForeignId('recurrence_id');
            $table->dropColumn('occurrence_date');
        });

        Schema::dropIfExists('recurrences');
    }
};
