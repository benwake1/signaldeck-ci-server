<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('test_suite_id')->constrained()->cascadeOnDelete();
            $table->foreignId('triggered_by')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('pending'); // pending, cloning, installing, running, passing, failed, error, cancelled
            $table->string('branch');
            $table->string('commit_sha')->nullable();
            $table->integer('total_tests')->default(0);
            $table->integer('passed_tests')->default(0);
            $table->integer('failed_tests')->default(0);
            $table->integer('pending_tests')->default(0);
            $table->integer('duration_ms')->nullable();
            $table->text('log_output')->nullable();
            $table->text('error_message')->nullable();
            $table->string('report_html_path')->nullable();
            $table->string('report_pdf_path')->nullable();
            $table->string('merged_json_path')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_runs');
    }
};
