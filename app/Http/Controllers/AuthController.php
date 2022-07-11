<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Session;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    public function redirect(): \Symfony\Component\HttpFoundation\RedirectResponse|RedirectResponse
    {
        return Socialite::driver('bitbucket')->redirect();
    }

    public function callback(): Redirector|Application|RedirectResponse
    {
        $user = Socialite::driver('bitbucket')->user();
        Session::put('user', $user);
        return redirect('/');
    }

    public function destroy(): Redirector|Application|RedirectResponse
    {
        Session::forget('user');
        return redirect('/');
    }
}
