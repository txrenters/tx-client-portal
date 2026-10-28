<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The months each owner was emailed their income statement, so a rerun
     * never emails the same month twice.
     */
    public function up(): void
    {
        Schema::create('owner_statement_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_account_id')->constrained()->cascadeOnDelete();
            // The month the statement covers, as YYYY-MM.
            $table->string('month', 7);
            $table->unsignedSmallInteger('portfolios');
            $table->timestamps();

            $table->unique(['client_account_id', 'month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('owner_statement_emails');
    }
};
