<?php

namespace App\Console\Commands;

use App\Services\Sync;
use Illuminate\Console\Command;
use Throwable;

class SyncData extends Command
{
    protected $signature = 'reviewer:sync {--full : Re-read everything instead of only what changed}';

    protected $description = 'Copy open pull requests, workspace members and Jira issues into the local database';

    public function handle(Sync $sync): int
    {
        // Cron has no logged-in user: use BITBUCKET_EMAIL + BITBUCKET_API_TOKEN (see config/bitbucket.php).
        config()->set('bitbucket.default', 'alternative');

        $email = config('bitbucket.connections.alternative.username');
        if (!$email || !config('bitbucket.connections.alternative.password')) {
            $this->error('Set BITBUCKET_EMAIL and BITBUCKET_API_TOKEN in .env (Atlassian account email + API token with Bitbucket scopes).');
            return self::FAILURE;
        }

        try {
            $stats = $sync->run(fn(string $message) => $this->line($message), (bool) $this->option('full'));
        } catch (Throwable $e) {
            $this->error('Sync failed: ' . $e->getMessage());
            if ($e->getCode() === 401) {
                $this->line(str_contains($email, '@')
                    ? 'Bitbucket rejected BITBUCKET_EMAIL / BITBUCKET_API_TOKEN: check the token is valid and has the Bitbucket read scopes.'
                    : "BITBUCKET_EMAIL is \"$email\", which isn't an email address. API tokens need your Atlassian account email (an old app password setup used the Bitbucket username).");
            }
            return self::FAILURE;
        }

        $this->info(sprintf(
            '%s sync: %d pull requests (%d new or changed) from %d of %d repositories, %d Jira issues (%d fetched) in %.1fs, %d Bitbucket requests.',
            $stats['full'] ? 'Full' : 'Quick',
            $stats['pull_requests'], $stats['changed_pull_requests'], $stats['scanned_repositories'], $stats['repositories'],
            $stats['jira_issues'], $stats['fetched_jira_issues'], $stats['seconds'], $stats['requests'],
        ));

        return self::SUCCESS;
    }
}
