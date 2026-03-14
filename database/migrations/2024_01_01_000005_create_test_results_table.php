<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_run_id')->constrained()->cascadeOnDelete();
            $table->string('spec_file');
            $table->string('suite_title')->nullable();
            $table->string('test_title');
            $table->string('full_title')->nullable();
            $table->string('status'); // passed, failed, pending, skipped
            $table->integer('duration_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->text('error_stack')->nullable();
            $table->text('test_code')->nullable();
            $table->json('screenshot_paths')->nullable();
            $table->string('video_path')->nullable();
            $table->integer('attempt')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_results');
    }
};
