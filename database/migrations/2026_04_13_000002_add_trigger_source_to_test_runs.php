<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_runs', function (Blueprint $table) {
            // triggered_by is the existing user FK — do not modify it.
            // trigger_source records how the run was initiated.
            $table->string('trigger_source', 32)->nullable()->after('triggered_by');
        });
    }

    public function down(): void
    {
        Schema::table('test_runs', function (Blueprint $table) {
            $table->dropColumn('trigger_source');
        });
    }
};
