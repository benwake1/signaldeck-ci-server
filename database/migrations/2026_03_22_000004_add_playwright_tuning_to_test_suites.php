<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_suites', function (Blueprint $table) {
            $table->unsignedSmallInteger('playwright_workers')->nullable()->after('playwright_projects');
            $table->unsignedSmallInteger('playwright_retries')->nullable()->after('playwright_workers');
        });
    }

    public function down(): void
    {
        Schema::table('test_suites', function (Blueprint $table) {
            $table->dropColumn(['playwright_workers', 'playwright_retries']);
        });
    }
};
