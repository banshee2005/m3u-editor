<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_profile_map', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('source_channel_id');
            $table->unsignedBigInteger('target_playlist_id');
            $table->unsignedBigInteger('target_channel_id');
            $table->timestamps();

            $table->foreign('source_channel_id')->references('id')->on('channels')->cascadeOnDelete();
            $table->foreign('target_playlist_id')->references('id')->on('playlists')->cascadeOnDelete();
            $table->foreign('target_channel_id')->references('id')->on('channels')->cascadeOnDelete();

            $table->unique(['source_channel_id', 'target_playlist_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_profile_map');
    }
};
