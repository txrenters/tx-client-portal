<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The last status each client was shown for each of their work orders, so
     * the half-hourly check tells them when it changes, and only once.
     */
    public function up(): void
    {
        Schema::create('work_order_status_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_account_id')->constrained()->cascadeOnDelete();
            $table->string('work_order_id');
            $table->string('status');
            // When the client was last told; null while a status was only recorded.
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['client_account_id', 'work_order_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_order_status_notices');
    }
};
