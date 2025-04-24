<?php

namespace App\Http\Controllers;

use App\Services\Bitbucket;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ConfigurationController extends Controller
{
    public function index(Bitbucket $bitbucket): View
    {
        return view('configuration.index', [
            'users' => $bitbucket->getAllUsers(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $users = $request->get('users');
        foreach ($users as $userData) {
            if (!$userData['email']) {
                continue;
            }

            $uuid = str_replace(['{', '}'], '', $userData['uuid']);
            $user = User::find($uuid);
            if ($user === null) {
                $user = new User();
                $user->uuid = $uuid;
                $user->name = $userData['name'];
            }
            $user->email = $userData['email'];
            $user->save();
        }

        return redirect()->back();
    }
}
