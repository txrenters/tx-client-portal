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
        Schema::table('users', function (Blueprint $table) {
            // admin or staff, granted from the admin area. The ADMIN_EMAILS
            // owners are not stored here: they come from config and cannot be
            // changed from inside the app.
            $table->string('staff_role')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['staff_role']);
            $table->dropColumn('staff_role');
        });
    }
};
