<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('test_suite_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            $table->json('messages')->nullable();
            $table->json('crawl_data')->nullable();
            $table->string('status')->default('active'); // active, completed, failed
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('managed_test_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_suite_id')->constrained()->cascadeOnDelete();
            $table->string('file_path');
            $table->longText('content');
            $table->integer('version')->default(1);
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['test_suite_id', 'file_path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_test_files');
        Schema::dropIfExists('ai_conversations');
    }
};
