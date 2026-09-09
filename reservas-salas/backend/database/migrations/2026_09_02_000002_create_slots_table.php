<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slots', function (Blueprint $table) {
            $table->id();
            $table->tinyInteger('day_of_week')->unsigned()->comment('1=Lunes ... 5=Viernes');
            $table->time('start_time')->comment('07:00:00 ... 21:00:00 en punto');
            $table->tinyInteger('capacity')->unsigned()->default(4);
            $table->enum('status', ['abierta', 'bloqueada'])->default('abierta');
            $table->timestamps();

            $table->unique(['day_of_week', 'start_time'], 'slots_day_time_unique');
            $table->index('status');
        });

        \DB::statement("ALTER TABLE slots ADD CONSTRAINT chk_slots_day CHECK (day_of_week BETWEEN 1 AND 5)");
        \DB::statement("ALTER TABLE slots ADD CONSTRAINT chk_slots_capacity CHECK (capacity >= 1 AND capacity <= 50)");
    }

    public function down(): void
    {
        Schema::dropIfExists('slots');
    }
};
