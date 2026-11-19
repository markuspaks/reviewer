<?php

namespace App\Http\Controllers;

use App\Services\Bitbucket;
use App\Services\SendEmails;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Bitbucket $bitbucket): Factory|View|Application
    {
        return view('notifications.index', [
            'users' => $bitbucket->getUsersWithAssignedPullRequests($bitbucket->getAllUsers()),
        ]);
    }

    public function sendEmails(Request $request, Bitbucket $bitbucket): Factory|View|Application
    {
        $uuidsFilter = array_keys($request->input('send', []));
        $users = $bitbucket->getUsers($uuidsFilter);
        $usersWithPullRequests = $bitbucket->getUsersWithAssignedPullRequests($users);
        $sendEmails = new SendEmails();
        $sendEmails->sendEmails($usersWithPullRequests);

        return view('notifications.emails-sent', ['users' => $usersWithPullRequests]);
    }
}
