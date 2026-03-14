<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_suites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('spec_pattern')->default('cypress/e2e/**/*.cy.{js,jsx,ts,tsx}');
            $table->string('branch_override')->nullable(); // override project default branch
            $table->json('env_variables')->nullable(); // merge on top of project vars
            $table->integer('timeout_minutes')->default(30);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_suites');
    }
};
