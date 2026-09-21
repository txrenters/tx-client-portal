<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The last hub message each login has seen in each conversation, so the
     * portal can tell which conversations have unread replies.
     */
    public function up(): void
    {
        Schema::create('conversation_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('thread_id');
            $table->unsignedBigInteger('last_read_message_id');
            $table->timestamps();

            $table->unique(['user_id', 'thread_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_reads');
    }
};
