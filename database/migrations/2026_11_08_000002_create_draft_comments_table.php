<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Review comments written on the review page but not yet sent to Bitbucket.
     */
    public function up(): void
    {
        Schema::create('draft_comments', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_uuid');
            $table->string('repository'); // workspace/repository
            $table->unsignedBigInteger('pull_request_id');
            $table->unsignedBigInteger('parent_id')->nullable(); // Bitbucket comment being replied to
            $table->string('path', 1000)->nullable(); // file; null for a comment on the pull request
            $table->unsignedInteger('line')->nullable(); // null for a comment on the whole file
            $table->string('side', 3)->nullable(); // old / new
            $table->text('content');
            $table->timestamps();

            $table->index(['user_uuid', 'repository', 'pull_request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('draft_comments');
    }
};
