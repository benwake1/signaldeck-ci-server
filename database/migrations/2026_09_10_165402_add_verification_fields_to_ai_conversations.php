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
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->string('verification_status')->nullable()->after('framework');
            $table->text('verification_output')->nullable()->after('verification_status');
            $table->json('recording_data')->nullable()->after('crawl_data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropColumn(['verification_status', 'verification_output', 'recording_data']);
        });
    }
};
