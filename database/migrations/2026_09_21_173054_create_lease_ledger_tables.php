<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A local copy of Propertyware's lease ledgers.
     *
     * Reading a ledger costs one SOAP call per ten entries, so a long tenancy
     * takes seconds every time someone opens their lease. Keeping the entries
     * here means a page reads the database, and only what has happened since
     * the last visit is fetched again.
     */
    public function up(): void
    {
        Schema::create('lease_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->string('lease_id', 32)->index();
            // Propertyware's own id for the entry, so a re-read never duplicates it.
            $table->string('external_id', 32);
            $table->date('occurred_on');
            $table->string('kind', 16);
            $table->string('direction', 8);
            $table->decimal('amount', 12, 2);
            $table->string('description');
            $table->string('detail')->nullable();
            $table->string('reference', 64)->nullable();
            $table->timestamps();

            $table->unique(['lease_id', 'external_id']);
            $table->index(['lease_id', 'occurred_on']);
        });

        Schema::create('lease_ledger_syncs', function (Blueprint $table) {
            $table->string('lease_id', 32)->primary();
            // The window already read, and when we last looked.
            $table->date('synced_from');
            $table->date('synced_to');
            $table->timestamp('synced_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_ledger_entries');
        Schema::dropIfExists('lease_ledger_syncs');
    }
};
