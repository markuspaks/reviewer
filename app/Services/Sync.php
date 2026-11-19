<?php

namespace App\Services;

use App\Models\BitbucketUser;
use App\Models\JiraIssue;
use App\Models\PullRequest;
use App\Models\PullRequestSnapshot;
use App\Models\Setting;
use Bitbucket\Client;
use Bitbucket\HttpClient\Message\ResponseMediator;
use GrahamCampbell\Bitbucket\BitbucketManager;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Copies everything the pages need from Bitbucket and Jira into the local database.
 *
 * Runs only from the scheduler (`php artisan reviewer:sync`), with the default Bitbucket connection.
 */
class Sync
{
    public const LAST_SYNCED_AT = 'last_synced_at';
    public const LAST_SYNC_ERROR = 'last_sync_error';
    public const LAST_FULL_SYNC_AT = 'last_full_sync_at';
    /** JSON map of repository full name => updated_on, as of the last sync. */
    public const REPOSITORY_UPDATED_ON = 'repository_updated_on';

    /** Number of most recent pipelines per repository used to find each branch's latest pipeline. */
    protected const PIPELINES_PER_REPOSITORY = 100;

    protected Client $client;

    /** @var callable(string): void */
    protected $log;

    protected int $requests = 0;

    protected int $changedPullRequests = 0;

    public function __construct(
        protected BitbucketManager $bitbucket,
        protected Jira $jira,
    ) {
    }

    /**
     * @param callable(string): void|null $log progress output
     * @param bool $full re-read everything instead of only what changed (also happens every
     *                   config('reviewer.full_sync_minutes') minutes)
     * @return array{full: bool, users: int, repositories: int, scanned_repositories: int, pull_requests: int,
     *               changed_pull_requests: int, jira_issues: int, requests: int, seconds: float}
     * @throws Throwable
     */
    public function run(?callable $log = null, bool $full = false): array
    {
        $this->log = $log ?? fn(string $message) => null;

        $lastFullSyncAt = Setting::getValue(self::LAST_FULL_SYNC_AT);
        $full = $full
            || $lastFullSyncAt === null
            || Carbon::parse($lastFullSyncAt)->addMinutes(config('reviewer.full_sync_minutes'))->isPast();

        // Two runs must not overlap, e.g. a slow full sync and the next scheduled one.
        $lock = Cache::lock('reviewer-sync', 30 * 60);
        if (!$lock->get()) {
            throw new LockTimeoutException('Another sync is already running.');
        }

        try {
            $startedAt = now()->toIso8601String();
            $stats = $this->sync($full);
            Setting::setValue(self::LAST_SYNCED_AT, $startedAt);
            if ($full) {
                Setting::setValue(self::LAST_FULL_SYNC_AT, $startedAt);
            }
            Setting::setValue(self::LAST_SYNC_ERROR, null);

            return $stats;
        } catch (Throwable $e) {
            Setting::setValue(self::LAST_SYNC_ERROR, now()->toIso8601String() . ' ' . $e->getMessage());
            throw $e;
        } finally {
            $lock->release();
        }
    }

    protected function sync(bool $full): array
    {
        $startedAt = microtime(true);
        $this->client = $this->bitbucket->connection();
        $this->requests = 0;
        $this->changedPullRequests = 0;
        ($this->log)($full ? 'Full sync' : 'Quick sync (only what changed)');

        // GET /2.0/user/workspaces returns workspace_access entries; the workspace itself is nested.
        $workspaces = array_map(
            fn(array $access) => $access['workspace'] ?? $access,
            $this->fetchAllPages('user/workspaces', ['pagelen' => 100])
        );

        // Members rarely change: only read them on a full sync.
        $users = null;
        $repositories = [];
        foreach ($workspaces as $workspace) {
            $slug = rawurlencode($workspace['slug']);
            if ($full) {
                foreach ($this->fetchAllPages("workspaces/$slug/members", ['pagelen' => 100]) as $membership) {
                    $users[$membership['user']['uuid']] = $membership['user'];
                }
            }
            array_push($repositories, ...$this->fetchAllPages("repositories/$slug", [
                'role' => 'member',
                'pagelen' => 100,
                'fields' => 'next,values.full_name,values.updated_on',
            ]));
        }
        ($this->log)(count($workspaces) . ' workspaces, ' . count($repositories) . ' repositories'
            . ($users !== null ? ', ' . count($users) . ' users' : ''));

        $stored = PullRequestSnapshot::all()->groupBy('repository');
        $knownUpdatedOn = json_decode(Setting::getValue(self::REPOSITORY_UPDATED_ON) ?? '[]', true);

        $snapshots = [];
        $updatedOn = [];
        $scanned = 0;
        foreach ($repositories as $repository) {
            $fullName = $repository['full_name'];
            $updatedOn[$fullName] = $repository['updated_on'] ?? null;
            $storedSnapshots = $stored->get($fullName, collect())->keyBy('pull_request_id');

            // A repository without open pull requests gets a new one only after a push
            // (which changes its updated_on); the full sync catches anything else.
            $mayHavePullRequests = $full
                || $storedSnapshots->isNotEmpty()
                || ($knownUpdatedOn[$fullName] ?? null) !== $updatedOn[$fullName];
            if (!$mayHavePullRequests) {
                continue;
            }

            $scanned++;
            array_push($snapshots, ...$this->syncRepository($fullName, $storedSnapshots->all(), $full));
        }
        ($this->log)("Scanned $scanned repositories: " . count($snapshots) . ' open pull requests, '
            . $this->changedPullRequests . ' new or changed');

        $issueKeys = array_values(array_filter(array_unique(array_column($snapshots, 'jira_issue_key'))));
        // Each issue is read from the Jira site of the workspace its pull requests are in.
        $issueSites = [];
        foreach ($snapshots as $snapshot) {
            if ($snapshot['jira_issue_key'] !== null) {
                $issueSites[$snapshot['jira_issue_key']] ??= $this->jira->siteUrl(explode('/', $snapshot['repository'], 2)[0]);
            }
        }
        $issues = [];
        foreach (array_keys(array_flip($issueSites)) as $site) {
            $issues += $this->fetchJiraIssues(array_keys(array_filter($issueSites, fn($s) => $s === $site)), $full, $site);
        }
        ($this->log)(count($issueKeys) . ' Jira issues, ' . count($issues) . ' fetched');

        DB::transaction(function () use ($users, $snapshots, $issues, $issueKeys, $updatedOn) {
            if ($users !== null) {
                $this->saveUsers($users);
            }
            $this->saveSnapshots($snapshots);
            $this->saveJiraIssues($issues, $issueKeys);
            Setting::setValue(self::REPOSITORY_UPDATED_ON, json_encode($updatedOn));
        });

        return [
            'full' => $full,
            'users' => $users !== null ? count($users) : BitbucketUser::count(),
            'repositories' => count($repositories),
            'scanned_repositories' => $scanned,
            'pull_requests' => count($snapshots),
            'changed_pull_requests' => $this->changedPullRequests,
            'jira_issues' => count($issueKeys),
            'fetched_jira_issues' => count($issues),
            'requests' => $this->requests,
            'seconds' => round(microtime(true) - $startedAt, 1),
        ];
    }

    /**
     * Open pull requests of one repository, with participants, latest pipeline, diff size and (when needed) comments.
     * Comments and pipelines of pull requests that haven't changed are reused from the last sync.
     *
     * @param array<int, PullRequestSnapshot> $stored this repository's snapshots from the last sync, by pull request id
     */
    protected function syncRepository(string $fullName, array $stored, bool $full): array
    {
        $path = 'repositories/' . implode('/', array_map('rawurlencode', explode('/', $fullName)));

        // Participants aren't in the list response by default; "+values.participants" adds them,
        // which saves fetching every pull request on its own.
        $pullRequests = $this->fetchAllPages("$path/pullrequests", [
            'state' => 'OPEN',
            'pagelen' => 50,
            'fields' => '+values.participants,+values.reviewers',
        ]);
        if (!$pullRequests) {
            return [];
        }

        $changed = [];
        foreach ($pullRequests as $i => $data) {
            if (!array_key_exists('participants', $data)) {
                $pullRequests[$i] = $data = $this->getJson("$path/pullrequests/{$data['id']}");
            }
            $previous = $stored[$data['id']] ?? null;
            $changed[$data['id']] = !$previous || self::fingerprint($previous->data) !== self::fingerprint($data);
        }
        $this->changedPullRequests += count(array_filter($changed));

        // A new pipeline comes from a push (which changes the pull request) or from a manual run
        // (caught by the full sync). A running pipeline has to be checked until it finishes.
        $pipelinesMayHaveChanged = $full || in_array(true, $changed, true);
        foreach ($stored as $previous) {
            $pipelinesMayHaveChanged = $pipelinesMayHaveChanged || self::isRunning($previous->last_pipeline);
        }
        $pipelines = $pipelinesMayHaveChanged ? $this->latestPipelinesByBranch($path) : null;

        $snapshots = [];
        foreach ($pullRequests as $data) {
            $previous = $stored[$data['id']] ?? null;
            $isChanged = $changed[$data['id']];
            $branch = $data['source']['branch']['name'];

            if ($pipelines === null) {
                $pipeline = $previous->last_pipeline;
            } elseif (array_key_exists($branch, $pipelines)) {
                $pipeline = $pipelines[$branch];
            } elseif ($previous && !$isChanged && !self::isRunning($previous->last_pipeline)) {
                // Not among the recent pipelines, so nothing new ran since we last looked it up.
                $pipeline = $previous->last_pipeline;
            } else {
                $pipeline = $this->latestPipelineForBranch($path, $branch);
            }

            $comments = null;
            if ($this->needsComments($data)) {
                // Comment activity changes the pull request's updated_on; the full sync re-reads them anyway.
                $comments = !$full && !$isChanged && $previous?->comments !== null
                    ? $previous->comments
                    : $this->fetchAllPages("$path/pullrequests/{$data['id']}/comments", ['sort' => '-updated_on', 'pagelen' => 100]);
            }

            $snapshots[] = [
                'repository' => $fullName,
                'pull_request_id' => $data['id'],
                'jira_issue_key' => Jira::findIssueKey($data['title'] ?? null, $branch),
                'data' => $data,
                'comments' => $comments,
                'last_pipeline' => $pipeline,
                'diffstat' => $this->diffstat($path, $data, $previous?->diffstat),
                'pull_request_updated_on' => $data['updated_on'],
            ];
        }

        return $snapshots;
    }

    /**
     * Number of files and lines the pull request changes. Re-read only when its source or
     * destination commit moved; if Bitbucket can't produce it (e.g. a huge diff), the last one is kept.
     *
     * @return array{files: int, lines_added: int, lines_removed: int, source: ?string, destination: ?string}|null
     */
    protected function diffstat(string $repositoryPath, array $pullRequest, ?array $previous): ?array
    {
        $source = $pullRequest['source']['commit']['hash'] ?? null;
        $destination = $pullRequest['destination']['commit']['hash'] ?? null;
        if ($previous !== null && ($previous['source'] ?? null) === $source && ($previous['destination'] ?? null) === $destination) {
            return $previous;
        }

        try {
            $files = $this->fetchAllPages("$repositoryPath/pullrequests/{$pullRequest['id']}/diffstat", [
                'pagelen' => 500,
                'fields' => 'next,values.lines_added,values.lines_removed',
            ]);
        } catch (Throwable $e) {
            ($this->log)("Diffstat of $repositoryPath/pullrequests/{$pullRequest['id']} failed: {$e->getMessage()}");

            return $previous;
        }

        return [
            'files' => count($files),
            'lines_added' => array_sum(array_map(fn(array $file) => (int) ($file['lines_added'] ?? 0), $files)),
            'lines_removed' => array_sum(array_map(fn(array $file) => (int) ($file['lines_removed'] ?? 0), $files)),
            'source' => $source,
            'destination' => $destination,
        ];
    }

    /**
     * What decides whether a pull request changed: activity, pushes and reviewer states.
     */
    protected static function fingerprint(array $pullRequest): string
    {
        return json_encode([
            $pullRequest['updated_on'] ?? null,
            $pullRequest['source']['commit']['hash'] ?? null,
            $pullRequest['source']['branch']['name'] ?? null,
            array_map(
                fn(array $participant) => [$participant['user']['uuid'] ?? null, $participant['state'] ?? null],
                $pullRequest['participants'] ?? []
            ),
        ]);
    }

    /**
     * Still running, so its result can change without the pull request changing.
     * A pipeline paused at a manual step counts as done (PullRequest shows it as successful);
     * if someone resumes it, the full sync picks that up.
     */
    protected static function isRunning(?array $pipeline): bool
    {
        return $pipeline !== null
            && ($pipeline['state']['name'] ?? null) !== 'COMPLETED'
            && ($pipeline['state']['stage']['name'] ?? null) !== 'PAUSED';
    }

    /**
     * Comments are only read for "review again" requests after someone requested changes.
     */
    protected function needsComments(array $pullRequest): bool
    {
        foreach ($pullRequest['participants'] ?? [] as $participant) {
            if (($participant['state'] ?? null) === PullRequest::STATE_CHANGES_REQUESTED) {
                return true;
            }
        }

        return false;
    }

    /**
     * Latest pipeline per branch among the repository's most recent pipelines (one request per repository).
     *
     * @return array<string, array>
     */
    protected function latestPipelinesByBranch(string $repositoryPath): array
    {
        $page = $this->getJson("$repositoryPath/pipelines/", [
            'sort' => '-created_on',
            'pagelen' => self::PIPELINES_PER_REPOSITORY,
        ], allowNotFound: true);

        $latest = [];
        foreach ($page['values'] ?? [] as $pipeline) {
            $branch = $pipeline['target']['ref_name'] ?? $pipeline['target']['source'] ?? null;
            if ($branch !== null && !isset($latest[$branch])) {
                $latest[$branch] = $pipeline;
            }
        }

        return $latest;
    }

    /**
     * Fallback for branches whose latest pipeline is older than the repository's recent pipelines.
     */
    protected function latestPipelineForBranch(string $repositoryPath, string $branch): ?array
    {
        $page = $this->getJson("$repositoryPath/pipelines/", [
            'target.branch' => $branch,
            'sort' => '-created_on',
            'pagelen' => 1,
        ], allowNotFound: true);

        return $page['values'][0] ?? null;
    }

    /**
     * Issues to (re)write: all of them on a full sync; otherwise new ones and those updated in Jira since the last sync.
     *
     * @param string[] $keys
     * @return array<string, array{summary: ?string, assignee: ?array}>
     */
    protected function fetchJiraIssues(array $keys, bool $full, ?string $siteUrl = null): array
    {
        if (!$keys || !$this->jira->isConfigured()) {
            return [];
        }

        $fetched = null;
        $lastSyncedAt = Setting::getValue(self::LAST_SYNCED_AT);
        if (!$full && $lastSyncedAt !== null) {
            $known = JiraIssue::whereIn('key', $keys)->pluck('key')->all();
            // Two extra minutes so nothing changed while the last sync was running is missed.
            $minutes = (int) ceil(Carbon::parse($lastSyncedAt)->diffInMinutes(now(), true)) + 2;
            $updated = $known ? $this->jira->fetchIssuesUpdatedWithin($known, $minutes, $siteUrl) : [];
            if ($updated !== null) {
                $fetched = $updated + $this->jira->fetchIssues(array_values(array_diff($keys, $known)), $siteUrl);
            }
        }
        $fetched ??= $this->jira->fetchIssues($keys, $siteUrl);

        $issues = [];
        foreach ($fetched as $key => $fields) {
            if ($fields !== null) {
                $issues[$key] = ['summary' => $fields['summary'] ?? null, 'assignee' => $fields['assignee'] ?? null];
            }
        }

        return $issues;
    }

    protected function saveUsers(array $users): void
    {
        foreach ($users as $uuid => $user) {
            BitbucketUser::updateOrCreate(['uuid' => $uuid], [
                'account_id' => $user['account_id'] ?? null,
                'nickname' => $user['nickname'] ?? $user['display_name'] ?? null,
                'data' => $user,
            ]);
        }
        BitbucketUser::whereNotIn('uuid', array_keys($users))->delete();
    }

    protected function saveSnapshots(array $snapshots): void
    {
        $keep = [];
        foreach ($snapshots as $snapshot) {
            $keep[] = PullRequestSnapshot::updateOrCreate(
                ['repository' => $snapshot['repository'], 'pull_request_id' => $snapshot['pull_request_id']],
                $snapshot,
            )->id;
        }
        // Merged or declined since the last sync.
        PullRequestSnapshot::whereNotIn('id', $keep)->delete();
    }

    /**
     * @param array<string, array> $issues fetched issues to write
     * @param string[] $referencedKeys every issue key an open pull request refers to
     */
    protected function saveJiraIssues(array $issues, array $referencedKeys): void
    {
        foreach ($issues as $key => $issue) {
            JiraIssue::updateOrCreate(['key' => $key], $issue);
        }
        if ($this->jira->isConfigured()) {
            JiraIssue::whereNotIn('key', $referencedKeys)->delete();
        }
    }

    /**
     * GET a Bitbucket API 2.0 collection and return the values of every page.
     */
    protected function fetchAllPages(string $path, array $params = []): array
    {
        $values = [];
        $uri = '/2.0/' . $path . '?' . http_build_query($params);
        while ($uri !== null) {
            $page = $this->getUri($uri);
            array_push($values, ...($page['values'] ?? []));

            $next = $page['next'] ?? null;
            $uri = $next ? parse_url($next, PHP_URL_PATH) . '?' . parse_url($next, PHP_URL_QUERY) : null;
        }

        return $values;
    }

    /**
     * @param bool $allowNotFound return [] instead of failing (e.g. Pipelines not enabled for a repository)
     */
    protected function getJson(string $path, array $params = [], bool $allowNotFound = false): array
    {
        try {
            return $this->getUri('/2.0/' . $path . ($params ? '?' . http_build_query($params) : ''));
        } catch (\Bitbucket\Exception\RuntimeException $e) {
            if ($allowNotFound && $e->getCode() === 404) {
                return [];
            }
            throw $e;
        }
    }

    protected function getUri(string $uri): array
    {
        $this->requests++;

        return ResponseMediator::getContent($this->client->getHttpClient()->get($uri));
    }
}
