<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('slot_id')->constrained('slots')->cascadeOnDelete();
            $table->date('week_start')->comment('Lunes 00:00 semana ISO Europe/Madrid');
            $table->enum('status', ['confirmada'])->default('confirmada');
            $table->timestamps();

            $table->unique(['user_id', 'slot_id', 'week_start'], 'reservations_user_slot_week_unique');
            $table->index(['slot_id', 'week_start'], 'reservations_slot_week_idx');
            $table->index(['user_id', 'week_start'], 'reservations_user_week_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
