<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A repair-initiated conversation (see TestRepairService) has no human
     * author, so user_id needs to allow null rather than being misattributed
     * to an arbitrary account.
     */
    public function up(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
        });

        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
        });

        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
