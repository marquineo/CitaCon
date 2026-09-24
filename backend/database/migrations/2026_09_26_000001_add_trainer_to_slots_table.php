<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slots', function (Blueprint $table) {
            $table->enum('trainer', ['Carlos', 'Alicia'])->nullable()->after('capacity');
        });
    }

    public function down(): void
    {
        Schema::table('slots', function (Blueprint $table) {
            $table->dropColumn('trainer');
        });
    }
};
