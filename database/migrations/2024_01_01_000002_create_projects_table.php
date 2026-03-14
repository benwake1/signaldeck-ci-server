<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('repo_url');
            $table->string('repo_provider')->default('github'); // github, bitbucket, gitlab
            $table->string('default_branch')->default('main');
            $table->text('deploy_key_private')->nullable(); // encrypted
            $table->string('deploy_key_public')->nullable();
            $table->json('env_variables')->nullable(); // encrypted JSON
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
