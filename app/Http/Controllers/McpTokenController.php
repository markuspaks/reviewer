<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * The logged-in user's personal token for connecting AI agents to the MCP server. Only its hash is stored,
 * so the token is shown once, right after it is created.
 */
class McpTokenController extends Controller
{
    public function store(): RedirectResponse
    {
        $token = 'rvw_' . Str::random(40);
        $user = Auth::user();
        $user->forceFill(['mcp_token' => hash('sha256', $token)])->save();

        return redirect()->to(url('configuration') . '#mcp')->with('mcp_token', $token);
    }

    public function destroy(): RedirectResponse
    {
        Auth::user()->forceFill(['mcp_token' => null])->save();

        return redirect()->to(url('configuration') . '#mcp')->with('status', 'MCP token removed. Agents using it can no longer connect.');
    }
}
