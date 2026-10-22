<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When each tenant payment reached the bank, so a tenant can tell a
     * settled payment from one still on its way.
     *
     * The sync times are cleared so every lease is read again on its next
     * visit and picks the dates up, rather than waiting out the day its copy
     * is trusted for. The entries themselves stay, so nothing goes blank.
     */
    public function up(): void
    {
        Schema::table('lease_ledger_entries', function (Blueprint $table) {
            // False when Propertyware's payment record wasn't read, so the
            // line shows no status rather than a wrong one.
            $table->boolean('deposit_checked')->default(false);
            $table->date('deposited_on')->nullable();
        });

        DB::table('lease_ledger_syncs')->delete();
    }

    public function down(): void
    {
        Schema::table('lease_ledger_entries', function (Blueprint $table) {
            $table->dropColumn(['deposit_checked', 'deposited_on']);
        });
    }
};
