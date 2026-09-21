<?php

use App\Enums\ClientRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Rename the stored client role so it matches what TexasRenters.com calls
     * the portal: residents rather than tenants.
     */
    public function up(): void
    {
        DB::table('client_accounts')->where('role', 'tenant')->update(['role' => ClientRole::Resident->value]);
        DB::table('client_invitations')->where('role', 'tenant')->update(['role' => ClientRole::Resident->value]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('client_accounts')->where('role', ClientRole::Resident->value)->update(['role' => 'tenant']);
        DB::table('client_invitations')->where('role', ClientRole::Resident->value)->update(['role' => 'tenant']);
    }
};
