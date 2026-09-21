<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep a permanent record of every owner approval decision made in the portal.
     *
     * Propertyware shows the company login as the approver of anything approved
     * through its API, so this is the trail of who actually decided.
     */
    public function up(): void
    {
        Schema::create('work_order_approvals', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_account_id')->constrained()->cascadeOnDelete();
            $table->string('propertyware_contact_id');
            $table->string('work_order_id');
            $table->string('work_order_number')->nullable();
            $table->string('decision');
            $table->text('note')->nullable();
            $table->string('approver_name');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('decided_at');
            // When Propertyware accepted the approval; null for declines and until it does.
            $table->timestamp('propertyware_synced_at')->nullable();
            $table->text('sync_error')->nullable();
            $table->timestamps();

            $table->index(['work_order_id', 'decided_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_order_approvals');
    }
};
