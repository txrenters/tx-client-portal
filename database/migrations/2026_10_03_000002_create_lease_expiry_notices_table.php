<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_expiry_notices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('propertyware_lease_id');
            $table->date('end_date');
            // Null when the first run recorded the lease without telling anyone.
            $table->timestamp('notified_at')->nullable();
            $table->unsignedInteger('recipients')->default(0);
            $table->timestamps();

            $table->unique(['propertyware_lease_id', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_expiry_notices');
    }
};
