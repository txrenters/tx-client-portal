<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let one login hold several Propertyware records in the same portal.
     *
     * Managers and family members routinely share an email address across
     * separate owner contacts, so the portal has to keep them apart and let
     * the client choose which one they are looking at.
     */
    public function up(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->string('label')->nullable()->after('propertyware_id');
            // MySQL backs the user_id foreign key with this index, so its replacement must exist first.
            $table->unique(['user_id', 'role', 'propertyware_id']);
            $table->dropUnique(['user_id', 'role']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * Rows beyond the first for a user and role are removed, because the old
     * unique index cannot exist while they do.
     */
    public function down(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->unique(['user_id', 'role']);
            $table->dropUnique(['user_id', 'role', 'propertyware_id']);
            $table->dropColumn('label');
        });
    }
};
