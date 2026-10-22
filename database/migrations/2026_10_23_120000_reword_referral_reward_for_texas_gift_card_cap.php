<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Texas law caps a referrer's reward at a $50 gift card, so the launch offer
 * of two free months each way goes. The owner who was referred still gets the
 * two free months. Only the launch wording is replaced: anything staff have
 * already written themselves is left as it is.
 */
return new class extends Migration
{
    private const OLD = "Refer a property owner and get 2 free months! They'll also get 2 free months.";

    private const NEW = "Refer a property owner and get a \$50 gift card! They'll get 2 months of management fees free.";

    public function up(): void
    {
        DB::table('settings')->where('key', 'referral_reward')->where('value', self::OLD)->update(['value' => self::NEW]);
        DB::table('promotions')->where('body', self::OLD)->update(['body' => self::NEW, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'referral_reward')->where('value', self::NEW)->update(['value' => self::OLD]);
        DB::table('promotions')->where('body', self::NEW)->update(['body' => self::OLD, 'updated_at' => now()]);
    }
};
