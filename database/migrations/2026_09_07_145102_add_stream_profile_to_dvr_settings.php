<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dvr_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('dvr_settings', 'stream_profile_id')) {
                $table->unsignedBigInteger('stream_profile_id')->nullable()->after('dvr_output_format');
            }
        });
    }

    public function down(): void
    {
        Schema::table('dvr_settings', function (Blueprint $table) {
            if (Schema::hasColumn('dvr_settings', 'stream_profile_id')) {
                $table->dropColumn('stream_profile_id');
            }
        });
    }
};
