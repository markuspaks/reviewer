<?php

namespace App\Services;

use App\Mail\AssignedPullRequests;
use Illuminate\Support\Facades\Mail;

class SendEmails
{
    public function sendEmails(array $users): void
    {
        foreach ($users as $user) {
            if (!count($user['pull_requests'])) {
                continue;
            }

            if (!$user['email']) {
                continue;
            }

            Mail::to($user['email'])->send(new AssignedPullRequests($user));
        }
    }
}
