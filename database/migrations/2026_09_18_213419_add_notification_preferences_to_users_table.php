<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which notifications a client wants; anything not set uses User::NOTIFICATION_DEFAULTS.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_preferences')->nullable()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_preferences');
        });
    }
};
