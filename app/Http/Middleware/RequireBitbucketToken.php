<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The review pages call Bitbucket as the user: without their API token, send them to add it.
 */
class RequireBitbucketToken
{
    public const MESSAGE = 'Add your Bitbucket API token on the Configuration page to review pull requests here.';

    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::user()?->hasBitbucketToken()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => self::MESSAGE], 403);
        }

        // Back to this page once the token is saved.
        $request->session()->put('url.intended', $request->fullUrl());

        return redirect()->to(url('configuration') . '#bitbucket')->with('warning', self::MESSAGE);
    }
}
