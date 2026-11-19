<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rules and notes given to AI agents through MCP: global ones, and ones for a single repository.
        Schema::create('agent_files', function (Blueprint $table) {
            $table->id();
            $table->string('repository')->nullable(); // workspace/repository; null for a global file
            $table->string('name');
            $table->longText('content');
            $table->timestamps();

            $table->unique(['repository', 'name']);
        });

        // Agents connect to the MCP server with a personal token (only its SHA-256 hash is kept).
        Schema::table('users', function (Blueprint $table) {
            $table->string('mcp_token', 64)->nullable()->unique();
        });

        // Unsent comments written by an agent: its model name and effort, shown as the author.
        Schema::table('draft_comments', function (Blueprint $table) {
            $table->string('author_name')->nullable()->after('user_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('draft_comments', fn(Blueprint $table) => $table->dropColumn('author_name'));
        Schema::table('users', fn(Blueprint $table) => $table->dropColumn('mcp_token'));
        Schema::dropIfExists('agent_files');
    }
};
