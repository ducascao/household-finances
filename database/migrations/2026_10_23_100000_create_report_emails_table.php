<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Histórico dos resumos enviados por e-mail (só o registro; o conteúdo não fica guardado).
        Schema::create('report_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->jsonb('recipients');
            $table->jsonb('sections');
            $table->string('view', 20);
            $table->date('month')->nullable();
            $table->timestamps();

            $table->index(['household_id', 'sender_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_emails');
    }
};
