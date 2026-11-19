<?php

namespace App\Models;

use App\Services\Jira;

/**
 * Pull requests that belong to the same Jira issue (or a single pull request without one).
 */
class PullRequestGroup
{
    /**
     * @param PullRequest[] $pullRequests newest first
     */
    public function __construct(
        protected ?string $issueKey,
        protected array $pullRequests,
    ) {
    }

    /**
     * Group by Jira issue key. Pull requests without a key get a group of their own.
     * Groups keep the order of their newest pull request.
     *
     * @param PullRequest[] $pullRequests newest first
     * @return PullRequestGroup[]
     */
    public static function fromPullRequests(array $pullRequests): array
    {
        $grouped = [];
        foreach ($pullRequests as $pullRequest) {
            $groupKey = $pullRequest->getJiraIssueKey() ?? 'pr:' . $pullRequest->getData()['links']['html']['href'];
            $grouped[$groupKey][] = $pullRequest;
        }

        $groups = [];
        foreach ($grouped as $groupKey => $groupPullRequests) {
            $issueKey = str_starts_with($groupKey, 'pr:') ? null : $groupKey;
            $groups[] = new self($issueKey, $groupPullRequests);
        }

        return $groups;
    }

    public function getIssueKey(): ?string
    {
        return $this->issueKey;
    }

    /**
     * @return PullRequest[]
     */
    public function getPullRequests(): array
    {
        return $this->pullRequests;
    }

    public function count(): int
    {
        return count($this->pullRequests);
    }

    /**
     * Jira issue summary, or else the newest pull request's title.
     */
    public function getTitle(): string
    {
        $summary = $this->issueKey ? app(Jira::class)->getIssueSummary($this->issueKey) : null;

        return $summary ?? $this->pullRequests[0]->getData()['title'];
    }

    /**
     * Jira issue link, or the pull request link when there is no issue.
     */
    public function getUrl(): ?string
    {
        if ($this->issueKey) {
            return app(Jira::class)->getIssueUrl($this->issueKey, $this->pullRequests[0]->getWorkspace());
        }

        return $this->pullRequests[0]->getData()['links']['html']['href'];
    }

    public function getUpdatedOn(): string
    {
        return max(array_map(fn(PullRequest $pullRequest) => $pullRequest->getData()['updated_on'], $this->pullRequests));
    }

    /**
     * Combined diff size of the group's pull requests, or null while any of them hasn't been synced yet.
     *
     * @return array{files: int, lines_added: int, lines_removed: int, lines: int}|null
     */
    public function getDiffSize(): ?array
    {
        $total = ['files' => 0, 'lines_added' => 0, 'lines_removed' => 0];
        foreach ($this->pullRequests as $pullRequest) {
            $size = $pullRequest->getDiffSize();
            if ($size === null) {
                return null;
            }
            foreach ($total as $key => $value) {
                $total[$key] = $value + $size[$key];
            }
        }
        $total['lines'] = $total['lines_added'] + $total['lines_removed'];

        return $total;
    }

    public function getAuthors(): array
    {
        return self::uniqueUsers(array_map(fn(PullRequest $pullRequest) => $pullRequest->getData()['author'], $this->pullRequests));
    }

    public function getAssignees(): array
    {
        return self::uniqueUsers(array_merge(...array_map(fn(PullRequest $pullRequest) => $pullRequest->getAssignees(), $this->pullRequests)));
    }

    protected static function uniqueUsers(array $users): array
    {
        $unique = [];
        foreach ($users as $user) {
            $unique[$user['account_id'] ?? $user['uuid'] ?? $user['nickname']] = $user;
        }

        return array_values($unique);
    }
}
