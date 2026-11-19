<?php

use App\Http\Middleware\AuthenticateMcpToken;
use App\Mcp\Servers\ReviewerServer;
use Laravel\Mcp\Facades\Mcp;

// MCP server for AI agents (Claude Code, Codex, Cursor, ...): tickets, pull requests, agent files, review comments.
// Agents authenticate with a personal token from the Configuration page.
Mcp::web('/mcp', ReviewerServer::class)->middleware(AuthenticateMcpToken::class);
