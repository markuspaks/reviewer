<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class BitbucketAuthentication
{
    public function handle(Request $request, Closure $next)
    {
        if (!Auth::user()) {
            return redirect('auth/redirect');
        }

        $token = Session::get('bitbucket_token');

        config()->set('bitbucket.connections.main.token', $token);

        return $next($request);
    }
}
