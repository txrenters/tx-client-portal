<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_push_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_account_id')->constrained()->cascadeOnDelete();
            $table->string('work_order_id');
            // Null when the owner was not pushed: the first run, or pushes switched off.
            $table->timestamp('pushed_at')->nullable();
            $table->timestamps();

            $table->unique(['client_account_id', 'work_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_push_notices');
    }
};
