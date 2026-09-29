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
        Schema::table('client_invitations', function (Blueprint $table) {
            // Staff can withdraw a link before it is used; kept rather than
            // deleted so the list still shows who was asked and when.
            $table->timestamp('cancelled_at')->nullable();
            // Null for invitations sent from the command line.
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('client_invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invited_by');
            $table->dropColumn('cancelled_at');
        });
    }
};
