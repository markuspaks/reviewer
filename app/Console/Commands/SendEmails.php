<?php

namespace App\Console\Commands;

use App\Services\Bitbucket;
use Illuminate\Console\Command;

class SendEmails extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'send-emails';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send emails for all pull requests';

    /**
     * Execute the console command.
     *
     * @param Bitbucket $bitbucket
     * @return int
     */
    public function handle(Bitbucket $bitbucket): int
    {
        // Reads the local copy made by reviewer:sync, so no Bitbucket credentials are needed here.
        $users = $bitbucket->getAllUsers();
        $usersWithPullRequests = $bitbucket->getUsersWithAssignedPullRequests($users);

        $sendEmails = new \App\Services\SendEmails();
        $sendEmails->sendEmails($usersWithPullRequests);

        return 0;
    }
}
