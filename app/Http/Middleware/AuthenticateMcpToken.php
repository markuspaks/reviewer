<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * MCP requests carry a user's personal token (Configuration → MCP for agents): "Authorization: Bearer <token>".
 * The agent then acts as that user: comments it adds are that user's unsent drafts.
 */
class AuthenticateMcpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $user = $token ? User::where('mcp_token', hash('sha256', $token))->first() : null;

        if ($user === null) {
            return response()->json([
                'error' => 'unauthorized',
                'message' => 'Missing or unknown MCP token. Create one on the Reviewer Configuration page and send it as "Authorization: Bearer <token>".',
            ], 401, ['WWW-Authenticate' => 'Bearer realm="reviewer"']);
        }

        Auth::setUser($user);

        return $next($request);
    }
}
