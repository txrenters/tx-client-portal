<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each meeting reminder sent, so the minute-by-minute check sends each one
     * once. The start time is part of the key: a meeting moved to a new time
     * is reminded about again.
     */
    public function up(): void
    {
        Schema::create('meeting_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Coresched's booking uid.
            $table->string('booking_uid');
            // "hour" or "five_minutes".
            $table->string('kind', 20);
            $table->timestamp('starts_at');
            $table->timestamps();

            $table->unique(['user_id', 'booking_uid', 'kind', 'starts_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meeting_reminders');
    }
};
