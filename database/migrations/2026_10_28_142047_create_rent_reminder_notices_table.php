<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The months a tenant was reminded their rent is due, so the daily check
     * reminds them at most once a month.
     */
    public function up(): void
    {
        Schema::create('rent_reminder_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('propertyware_lease_id');
            // The month reminded about, as YYYY-MM.
            $table->string('month', 7);
            $table->decimal('balance', 10, 2);
            $table->timestamps();

            $table->unique(['client_account_id', 'month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rent_reminder_notices');
    }
};
