<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('runner_type')->default('cypress')->after('repo_provider');
            $table->json('playwright_available_projects')->nullable()->after('runner_type');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['runner_type', 'playwright_available_projects']);
        });
    }
};
