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
        // Signs of someone probing the portal, beyond failed sign-ins (which
        // live in sign_in_events): scanner paths, clients trying admin pages,
        // invalid invitation links, bad webhook signatures, blocked requests.
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('ip_address', 45)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path', 500)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->index();

            $table->index(['type', 'created_at']);
            $table->index(['ip_address', 'created_at']);
        });

        // IP addresses refused on every request, by staff or automatically.
        Schema::create('blocked_ips', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address', 45)->unique();
            $table->string('reason', 500);
            // manual or automatic.
            $table->string('source');
            // Null blocks for good; automatic blocks always expire.
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('blocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blocked_ips');
        Schema::dropIfExists('security_events');
    }
};
