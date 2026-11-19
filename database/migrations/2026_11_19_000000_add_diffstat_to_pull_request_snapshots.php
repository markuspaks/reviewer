<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Size of each pull request's diff, for the reviews list: files changed and lines added/removed,
     * with the commits it was counted for (it is only re-read when they change).
     */
    public function up(): void
    {
        Schema::table('pull_request_snapshots', function (Blueprint $table) {
            $table->json('diffstat')->nullable()->after('last_pipeline');
        });
    }

    public function down(): void
    {
        Schema::table('pull_request_snapshots', function (Blueprint $table) {
            $table->dropColumn('diffstat');
        });
    }
};
