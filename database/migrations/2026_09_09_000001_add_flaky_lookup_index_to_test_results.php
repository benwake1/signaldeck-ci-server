<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FlakyTests (app/Filament/Pages/FlakyTests.php) runs a correlated subquery per
 * result row that filters on (spec_file, full_title, status) and orders by
 * created_at. The existing single-column indexes on those columns force a
 * filesort per row; this composite index lets that subquery use one index
 * seek instead, which is what was timing out once test_results grew large.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_results', function (Blueprint $table) {
            $table->index(
                ['spec_file', 'full_title', 'status', 'created_at'],
                'test_results_flaky_lookup_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('test_results', function (Blueprint $table) {
            $table->dropIndex('test_results_flaky_lookup_index');
        });
    }
};
