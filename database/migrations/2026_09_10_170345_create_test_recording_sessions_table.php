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
        Schema::create('test_recording_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('token')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('recording');
            $table->json('actions')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('test_recording_sessions');
    }
};
