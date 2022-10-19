<?php

namespace App\Console\Commands;

use App\Services\Bitbucket;
use Http\Client\Exception;
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
     * @throws Exception
     */
    public function handle(Bitbucket $bitbucket): int
    {
        config()->set('bitbucket.default', 'alternative');

        $users = $bitbucket->getAllUsers();
        $usersWithPullRequests = $bitbucket->getUsersWithAssignedPullRequests($users);

        $sendEmails = new \App\Services\SendEmails();
        $sendEmails->sendEmails($usersWithPullRequests);

        return 0;
    }
}
