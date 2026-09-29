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
        Schema::table('promotions', function (Blueprint $table) {
            // Names, not colours: each app maps them to its own palette, so
            // the popup stays on-brand in light and dark mode. See
            // Promotion::THEMES and Promotion::ICONS.
            $table->string('theme')->default('blue');
            $table->string('icon')->default('gift');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropColumn(['theme', 'icon']);
        });
    }
};
