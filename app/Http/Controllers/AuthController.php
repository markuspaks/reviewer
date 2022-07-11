<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Auth;
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
        $socialiteUser = Socialite::driver('bitbucket')->user();
        $uuid = str_replace(['{', '}'], '', $socialiteUser->id);
        $user = User::find($uuid);
        if (!$user) {
            $user = new User();
            $user->uuid = $uuid;
            $user->name = $socialiteUser->name;
            $user->email = $socialiteUser->email;
            $user->save();
        }
        Auth::login($user);
        Session::put('bitbucket_token', $socialiteUser->token);
        return redirect('/');
    }

    public function destroy(): Redirector|Application|RedirectResponse
    {
        Session::forget('bitbucket_token');
        Auth::logout();
        return redirect('/');
    }
}
