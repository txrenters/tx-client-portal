<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What anonymous visitors do on the landing page, once per visitor, event
     * and day. A visitor is a daily-rotating hash, never a person or address.
     */
    public function up(): void
    {
        Schema::create('landing_page_events', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->string('event', 32);
            $table->char('visitor', 64);
            $table->timestamp('created_at')->nullable();

            $table->unique(['day', 'event', 'visitor']);
            $table->index(['event', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_page_events');
    }
};
