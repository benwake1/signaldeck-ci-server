<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('run_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->nullable()->constrained('test_runs')->nullOnDelete();
            $table->string('event_type');   // run.updated | run.completed | dashboard.stats_updated
            $table->json('payload');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['run_id', 'id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('run_events');
    }
};
