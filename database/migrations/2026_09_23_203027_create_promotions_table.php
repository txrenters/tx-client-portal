<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->string('button_label');
            // "referrals" for the portal's Refer a friend page, or a full https:// address.
            $table->string('button_link');
            // all, owner or resident.
            $table->string('audience')->default('all');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);
            // Stop showing it to someone once they have sent a referral.
            $table->boolean('stop_after_referral')->default(false);
            $table->timestamps();
        });

        // The launch: the printed Refer a Friend card, once a day until they refer someone.
        DB::table('promotions')->insert([
            'title' => 'Refer a friend',
            'body' => "Refer a property owner and get 2 free months! They'll also get 2 free months.",
            'button_label' => 'Refer an owner',
            'button_link' => 'referrals',
            'audience' => 'all',
            'is_active' => true,
            'priority' => 0,
            'stop_after_referral' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
