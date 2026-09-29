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
        Schema::create('promotion_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The day (in the office's timezone) it last popped up, so it shows once a day.
            $table->date('last_shown_on')->nullable();
            $table->unsignedInteger('times_shown')->default(0);
            $table->timestamp('clicked_at')->nullable();
            // "Don't show this again".
            $table->timestamp('hidden_at')->nullable();
            $table->timestamps();

            $table->unique(['promotion_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promotion_views');
    }
};
