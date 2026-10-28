<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep a copy of each work order's approval as Propertyware shows it.
     *
     * Staff can approve, or rewrite the approval comment, directly in
     * Propertyware, which keeps no history of either. A row is added whenever
     * what Propertyware shows changes, and rows are never edited, so earlier
     * versions survive.
     */
    public function up(): void
    {
        Schema::create('propertyware_approvals', function (Blueprint $table) {
            $table->id();
            $table->string('work_order_id');
            $table->string('work_order_number')->nullable();
            $table->boolean('approved');
            $table->date('approved_date')->nullable();
            $table->string('approved_by')->nullable();
            $table->string('approved_by_email')->nullable();
            $table->text('approval_comment')->nullable();
            // When Propertyware last changed the work order, as its REST API reported it.
            $table->timestamp('work_order_modified_at')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['work_order_id', 'recorded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('propertyware_approvals');
    }
};
