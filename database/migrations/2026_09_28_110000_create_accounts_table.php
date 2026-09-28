<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('type', 20);
            $table->string('visibility', 20);
            $table->char('currency', 3)->default('BRL');
            $table->bigInteger('initial_balance')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['household_id', 'visibility', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
