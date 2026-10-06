<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // What the App Review logins create: work orders, documents and chat
        // messages that never leave the portal. `demo:reset` empties it nightly.
        Schema::create('demo_records', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 32)->index();
            $table->unsignedBigInteger('external_id')->nullable()->index();
            $table->json('payload');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('demo_records');
    }
};
