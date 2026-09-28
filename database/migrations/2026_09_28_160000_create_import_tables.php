<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mapeamento de CSV por conta. Colunas numeradas a partir de 1.
        Schema::create('import_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->char('delimiter', 1)->default(';');
            $table->unsignedSmallInteger('skip_lines')->default(0);
            $table->boolean('has_header')->default(true);
            $table->unsignedSmallInteger('date_column');
            $table->unsignedSmallInteger('description_column');
            $table->unsignedSmallInteger('amount_column')->nullable();
            $table->unsignedSmallInteger('debit_column')->nullable();
            $table->unsignedSmallInteger('credit_column')->nullable();
            $table->string('date_format', 20)->default('d/m/Y');
            $table->char('decimal_separator', 1)->default(',');
            $table->char('thousands_separator', 1)->nullable();
            $table->boolean('invert_sign')->default(false);
            $table->timestamps();
        });

        Schema::create('import_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('pattern');
            $table->foreignId('category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('description')->nullable();
            $table->boolean('ignore')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['household_id', 'position']);
        });

        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('file_name');
            $table->string('format', 10);
            $table->string('status', 20);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['household_id', 'status']);
        });

        Schema::create('import_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->date('date');
            $table->string('description');
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->char('hash', 64);
            $table->string('status', 20);
            $table->string('action', 20);
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('final_description')->nullable();
            $table->foreignId('import_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('matched_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->timestamps();

            $table->index(['import_batch_id', 'line_number']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->char('import_hash', 64)->nullable()->after('installment_number');

            $table->unique(['account_id', 'import_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['account_id', 'import_hash']);
            $table->dropColumn('import_hash');
        });

        Schema::dropIfExists('import_lines');
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('import_rules');
        Schema::dropIfExists('import_profiles');
    }
};
