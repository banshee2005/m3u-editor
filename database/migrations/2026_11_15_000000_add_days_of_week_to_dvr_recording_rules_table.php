<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('dvr_recording_rules', function (Blueprint $table) {
            // JSON array of weekday numbers (0=Sunday .. 6=Saturday, PHP date('w')),
            // or null/empty = every day. Series rules only.
            $table->json('days_of_week')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dvr_recording_rules', function (Blueprint $table) {
            $table->dropColumn('days_of_week');
        });
    }
};
