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
        Schema::create('push_tokens', function (Blueprint $table) {
            $table->id();
            // A phone that is handed on, or an app reinstalled under another
            // login, mints the same token again; it follows the newest login
            // rather than ringing for whoever registered it first.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token')->unique();
            $table->string('platform');
            $table->string('device_name')->nullable();
            // When Expo last took a push for this device, so dead phones are
            // recognisable even before Expo reports them as unregistered.
            $table->timestamp('last_pushed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('push_tokens');
    }
};
