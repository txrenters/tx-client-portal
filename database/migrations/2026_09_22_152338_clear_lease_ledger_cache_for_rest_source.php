<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Drop the ledger copy so it rebuilds from the REST API.
     *
     * Entries used to be keyed by the id SOAP gave them; they are now keyed by
     * the endpoint and id REST gives them, so the two would sit side by side
     * and every line would appear twice. Both tables are only a cache of
     * Propertyware, so clearing them costs nothing but the next read.
     */
    public function up(): void
    {
        DB::table('lease_ledger_entries')->delete();
        DB::table('lease_ledger_syncs')->delete();
    }

    public function down(): void
    {
        // Nothing to restore: the cache refills itself from Propertyware.
    }
};
