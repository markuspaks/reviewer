<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each user's own Atlassian API token, so the review page calls Bitbucket as them (replaces the OAuth login).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Atlassian account email for the token, when it isn't the login email.
            $table->string('bitbucket_email')->nullable()->after('email');
            // Encrypted with APP_KEY.
            $table->text('bitbucket_api_token')->nullable()->after('bitbucket_email');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn(Blueprint $table) => $table->dropColumn(['bitbucket_email', 'bitbucket_api_token']));
    }
};
