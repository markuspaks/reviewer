<?php

namespace App\Services;

use App\Models\JiraIssue;
use App\Models\Setting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Jira
{
    /**
     * Setting: Jira site per Bitbucket workspace, as JSON {"workspace": "https://site.atlassian.net"}.
     * Workspaces not listed use JIRA_URL. One Atlassian API token works on every site its account can open.
     */
    public const SITES = 'jira_sites';

    /**
     * Matches Jira issue keys such as ABC-123.
     */
    public const ISSUE_KEY_PATTERN = '/(?<![A-Za-z0-9])([A-Z][A-Z0-9_]+-[1-9][0-9]*)(?![A-Za-z0-9])/';

    /**
     * Issue fields per issue key read in this request; null means "not synced".
     *
     * @var array<string, array|null>
     */
    protected array $issues = [];

    public function isConfigured(): bool
    {
        return config('services.jira.url')
            && config('services.jira.email')
            && config('services.jira.api_token');
    }

    /**
     * Jira sites set per workspace on the Configuration page.
     *
     * @return array<string, string> workspace => site URL
     */
    public function workspaceSites(): array
    {
        return json_decode(Setting::getValue(self::SITES) ?? '[]', true) ?: [];
    }

    /**
     * The Jira site for a Bitbucket workspace: its own one, or JIRA_URL.
     */
    public function siteUrl(?string $workspace = null): ?string
    {
        $url = ($workspace !== null ? $this->workspaceSites()[$workspace] ?? null : null) ?? config('services.jira.url');

        return $url ? rtrim($url, '/') : null;
    }

    /**
     * Every Jira site in use: JIRA_URL first, then the workspaces' own ones.
     *
     * @return string[]
     */
    public function siteUrls(): array
    {
        return array_values(array_unique(array_filter([$this->siteUrl(), ...array_map(fn($url) => rtrim($url, '/'), $this->workspaceSites())])));
    }

    /**
     * First Jira issue key found in the given texts, checked in order.
     */
    public static function findIssueKey(?string ...$texts): ?string
    {
        foreach ($texts as $text) {
            if ($text !== null && preg_match(self::ISSUE_KEY_PATTERN, $text, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * The Jira assignee of the issue (accountId, displayName, avatarUrls, ...), or null.
     */
    public function getIssueAssignee(string $issueKey): ?array
    {
        return $this->getIssueFields($issueKey)['assignee'] ?? null;
    }

    public function getIssueSummary(string $issueKey): ?string
    {
        return $this->getIssueFields($issueKey)['summary'] ?? null;
    }

    /**
     * Link to the issue in Jira (on the workspace's site), or null when no site is set.
     */
    public function getIssueUrl(string $issueKey, ?string $workspace = null): ?string
    {
        $url = $this->siteUrl($workspace);

        return $url ? $url . '/browse/' . rawurlencode($issueKey) : null;
    }

    /**
     * Full issue from Jira, live: summary, status, type, priority, people, labels, description and comments
     * (rich text in Atlassian Document Format). Null when the issue doesn't exist or isn't visible.
     *
     * @throws \Illuminate\Http\Client\RequestException for other failures
     */
    public function fetchIssueDetails(string $issueKey, ?string $siteUrl = null): ?array
    {
        $response = $this->client(Http::createPendingRequest(), $siteUrl)
            ->timeout(30)
            ->get('/rest/api/3/issue/' . rawurlencode($issueKey), [
                'fields' => 'summary,status,issuetype,priority,assignee,reporter,labels,components,fixVersions,parent,'
                    . 'subtasks,issuelinks,created,updated,description,comment',
            ]);

        if ($response->notFound()) {
            return null;
        }

        return $response->throw()->json();
    }

    /**
     * The issue's assignee and summary, from the local copy made by the sync.
     */
    protected function getIssueFields(string $issueKey): ?array
    {
        if (!array_key_exists($issueKey, $this->issues)) {
            $issue = JiraIssue::find($issueKey);
            $this->issues[$issueKey] = $issue ? ['summary' => $issue->summary, 'assignee' => $issue->assignee] : null;
        }

        return $this->issues[$issueKey];
    }

    /**
     * Keys and fields of the given issues that changed in the last $minutes minutes (one search per 100 issues).
     * Used by the quick sync. Returns null when the search fails, so the caller can fetch everything instead.
     *
     * @param string[] $issueKeys issues that exist (already synced)
     * @return array<string, array>|null fields per changed issue key
     */
    public function fetchIssuesUpdatedWithin(array $issueKeys, int $minutes, ?string $siteUrl = null): ?array
    {
        $fields = [];
        foreach (array_chunk($issueKeys, 100) as $chunk) {
            // A relative date avoids timezone differences between this server and the Jira user's profile.
            $jql = 'issuekey in (' . implode(',', $chunk) . ") AND updated >= \"-{$minutes}m\"";
            $nextPageToken = null;
            do {
                $response = $this->client(Http::createPendingRequest(), $siteUrl)
                    ->timeout(30)
                    ->post('/rest/api/3/search/jql', array_filter([
                        'jql' => $jql,
                        'fields' => ['summary', 'assignee'],
                        'maxResults' => 100,
                        'nextPageToken' => $nextPageToken,
                    ]));

                if (!$response->successful()) {
                    Log::warning('Jira search for updated issues failed (HTTP ' . $response->status() . '), fetching all issues instead.');
                    return null;
                }

                foreach ($response->json('issues') ?? [] as $issue) {
                    $fields[$issue['key']] = $issue['fields'] ?? [];
                }
                $nextPageToken = $response->json('nextPageToken');
            } while ($nextPageToken);
        }

        return $fields;
    }

    /**
     * Fetch assignee and summary of the issues from Jira, 10 requests at a time. Used by the sync.
     *
     * @param string[] $issueKeys
     * @return array<string, array|null> fields per issue key; null when not found or failed
     */
    public function fetchIssues(array $issueKeys, ?string $siteUrl = null): array
    {
        $fields = [];
        foreach (array_chunk($issueKeys, 10) as $chunk) {
            $responses = Http::pool(fn(Pool $pool) => array_map(
                fn(string $issueKey) => $this->client($pool->as($issueKey), $siteUrl)
                    ->timeout(15)
                    ->get('/rest/api/3/issue/' . rawurlencode($issueKey), ['fields' => 'assignee,summary']),
                $chunk
            ));

            foreach ($chunk as $issueKey) {
                $response = $responses[$issueKey];
                if ($response instanceof Response && $response->successful()) {
                    $fields[$issueKey] = $response->json('fields');
                    continue;
                }

                $fields[$issueKey] = null;
                if (!($response instanceof Response && $response->notFound())) {
                    $error = $response instanceof Response ? 'HTTP ' . $response->status() : $response->getMessage();
                    Log::warning("Could not fetch Jira issue $issueKey: $error");
                }
            }
        }

        return $fields;
    }

    /**
     * A request to a Jira site (default JIRA_URL) with the Jira login.
     */
    protected function client(PendingRequest $request, ?string $siteUrl): PendingRequest
    {
        return $request->baseUrl(rtrim($siteUrl ?? (string) config('services.jira.url'), '/'))
            ->withBasicAuth((string) config('services.jira.email'), (string) config('services.jira.api_token'))
            ->acceptJson();
    }
}
