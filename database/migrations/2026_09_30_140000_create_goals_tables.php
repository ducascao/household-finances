<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->bigInteger('target');
            $table->date('start_date');
            $table->date('deadline');
            $table->string('visibility', 20);
            $table->timestamp('archived_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('goal_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();

            $table->unique(['goal_id', 'account_id']);
        });

        Schema::create('goal_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();

            $table->unique(['goal_id', 'asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goal_assets');
        Schema::dropIfExists('goal_accounts');
        Schema::dropIfExists('goals');
    }
};
