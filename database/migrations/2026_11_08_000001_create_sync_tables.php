<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local copy of Bitbucket and Jira data, filled by `php artisan reviewer:sync`.
 * Pages render only from these tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitbucket_users', function (Blueprint $table) {
            $table->string('uuid')->primary();
            $table->string('account_id')->nullable()->index();
            $table->string('nickname')->nullable();
            $table->json('data');
            $table->timestamps();
        });

        Schema::create('pull_request_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('repository');
            $table->unsignedInteger('pull_request_id');
            $table->string('jira_issue_key')->nullable()->index();
            $table->json('data');
            $table->json('comments')->nullable();
            $table->json('last_pipeline')->nullable();
            $table->timestamp('pull_request_updated_on')->index();
            $table->timestamps();

            $table->unique(['repository', 'pull_request_id']);
        });

        Schema::create('jira_issues', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('summary')->nullable();
            $table->json('assignee')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jira_issues');
        Schema::dropIfExists('pull_request_snapshots');
        Schema::dropIfExists('bitbucket_users');
    }
};
