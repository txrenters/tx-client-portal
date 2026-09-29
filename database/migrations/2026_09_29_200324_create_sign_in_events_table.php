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
        // Every sign-in and failed attempt, on the web and in the apps. The
        // email tried is kept even when it matches no login, since those are
        // the attempts worth noticing. Passwords are never stored.
        Schema::create('sign_in_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->nullable();
            // signed_in, failed, failed_two_factor, locked_out or blocked.
            $table->string('event');
            $table->string('channel');
            $table->string('method')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->index();

            $table->index(['event', 'created_at']);
            $table->index(['email', 'created_at']);
        });

        // A signed-in person coming back after half an hour or more away.
        Schema::create('portal_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel');
            $table->timestamp('created_at')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portal_visits');
        Schema::dropIfExists('sign_in_events');
    }
};
