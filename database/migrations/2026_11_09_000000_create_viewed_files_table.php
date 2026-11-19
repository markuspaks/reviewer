<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Files a user marked as viewed on the review page, with the version of the file's diff they saw.
     */
    public function up(): void
    {
        Schema::create('viewed_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_uuid');
            $table->string('repository'); // workspace/repository
            $table->unsignedBigInteger('pull_request_id');
            $table->text('path');
            $table->char('path_hash', 40); // sha1 of path, for the unique index (paths can be long)
            $table->string('version', 40); // Diff file version when marked as viewed
            $table->timestamps();

            $table->unique(['user_uuid', 'repository', 'pull_request_id', 'path_hash'], 'viewed_files_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viewed_files');
    }
};
