<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('closing_day');
            $table->unsignedTinyInteger('due_day');
            $table->bigInteger('limit')->default(0);
            // Cópia da moeda da conta, para o cast de Money.
            $table->char('currency', 3);
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credit_card_id')->constrained()->cascadeOnDelete();
            // Mês da fatura = mês do vencimento (dia 1).
            $table->date('reference_month');
            $table->date('closing_date');
            $table->date('due_date');
            // Aberta/fechada vem da data de fechamento; só o pagamento é gravado.
            $table->timestamp('paid_at')->nullable();
            $table->uuid('payment_transfer_id')->nullable();
            $table->timestamps();

            $table->unique(['credit_card_id', 'reference_month']);
            $table->index(['household_id', 'due_date']);
        });

        Schema::create('installment_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->bigInteger('total_amount');
            $table->char('currency', 3);
            $table->unsignedSmallInteger('installments');
            $table->string('description');
            $table->date('purchase_date');
            $table->timestamps();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->after('recurrence_id')->constrained()->restrictOnDelete();
            $table->foreignId('installment_group_id')->nullable()->after('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('installment_number')->nullable()->after('installment_group_id');

            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['invoice_id']);
            $table->dropConstrainedForeignId('invoice_id');
            $table->dropConstrainedForeignId('installment_group_id');
            $table->dropColumn('installment_number');
        });

        Schema::dropIfExists('installment_groups');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('credit_cards');
    }
};
