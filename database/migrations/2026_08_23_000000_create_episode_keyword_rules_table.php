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
        Schema::create('episode_keyword_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('playlist_id')->nullable()->constrained()->nullOnDelete();
            $table->string('expression', 500);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'enabled']);
            $table->index('playlist_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('episode_keyword_rules');
    }
};
