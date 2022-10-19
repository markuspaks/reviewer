<?php

namespace App\Http\Controllers;

use App\Services\Bitbucket;
use App\Services\SendEmails;
use Http\Client\Exception;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * @throws Exception
     */
    public function index(Bitbucket $bitbucket): Factory|View|Application
    {
        return view('notifications.index', [
            'users' => $bitbucket->getUsersWithAssignedPullRequests($bitbucket->getAllUsers()),
        ]);
    }

    /**
     * @throws Exception
     */
    public function sendEmails(Request $request, Bitbucket $bitbucket): Factory|View|Application
    {
        $uuidsFilter = array_keys($request->get('send'));
        $users = $bitbucket->getAllUsers($uuidsFilter);
        $usersWithPullRequests = $bitbucket->getUsersWithAssignedPullRequests($users);
        $sendEmails = new SendEmails();
        $sendEmails->sendEmails($usersWithPullRequests);

        return view('notifications.emails-sent', ['users' => $usersWithPullRequests]);
    }
}
