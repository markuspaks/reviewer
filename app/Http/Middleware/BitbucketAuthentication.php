<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class BitbucketAuthentication
{
    public function handle(Request $request, Closure $next)
    {
        if (!Session::get('user')) {
            return redirect('auth/redirect');
        }

        $user = Session::get('user');

        config()->set('bitbucket.connections.main.token', $user->token);

        return $next($request);
    }
}
