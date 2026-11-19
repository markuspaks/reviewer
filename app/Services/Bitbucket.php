<?php

namespace App\Services;

use App\Models\BitbucketUser;
use App\Models\PullRequest;
use App\Models\PullRequestSnapshot;
use App\Models\User;

/**
 * Bitbucket data for the pages, read from the local copy that the sync keeps fresh (see Sync).
 * Nothing here calls the Bitbucket API.
 */
class Bitbucket
{
    protected ?array $pullRequests = null;

    protected ?array $users = null;

    /**
     * Open pull requests, most recently updated first.
     *
     * @return PullRequest[]
     */
    public function getAllPullRequests(): array
    {
        if ($this->pullRequests !== null) {
            return $this->pullRequests;
        }

        return $this->pullRequests = PullRequestSnapshot::query()
            ->orderByDesc('pull_request_updated_on')
            ->get()
            ->map(fn(PullRequestSnapshot $snapshot) => new PullRequest(
                $this,
                $snapshot->data,
                $snapshot->comments ?? [],
                $snapshot->last_pipeline,
                $snapshot->diffstat,
            ))
            ->all();
    }

    /**
     * Workspace members keyed by uuid, with the email saved on the Configuration page.
     */
    public function getAllUsers(): array
    {
        if ($this->users !== null) {
            return $this->users;
        }

        $emails = User::pluck('email', 'uuid');

        $users = [];
        foreach (BitbucketUser::orderBy('nickname')->get() as $bitbucketUser) {
            $user = $bitbucketUser->data;
            $user['email'] = $emails[str_replace(['{', '}'], '', $bitbucketUser->uuid)] ?? null;
            $users[$bitbucketUser->uuid] = $user;
        }

        return $this->users = $users;
    }

    /**
     * Workspace member with the given Atlassian account ID (shared by Bitbucket and Jira).
     */
    public function findUserByAccountId(string $accountId): ?array
    {
        foreach ($this->getAllUsers() as $user) {
            if (($user['account_id'] ?? null) === $accountId) {
                return $user;
            }
        }

        return null;
    }

    public function getUsers(?array $uuidsFilter = null): array
    {
        return array_filter($this->getAllUsers(), fn($key) => in_array($key, $uuidsFilter), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param PullRequest[]|null $pullRequests only these pull requests (default all)
     */
    public function getUsersWithAssignedPullRequests(array $users, ?array $pullRequests = null): array
    {
        foreach ($users as $key => $user) {
            $users[$key]['pull_requests'] = [];
        }
        foreach ($pullRequests ?? $this->getAllPullRequests() as $pullRequest) {
            foreach ($pullRequest->getAssignees() as $assignee) {
                if (!isset($users[$assignee['uuid']])) {
                    continue;
                }
                $users[$assignee['uuid']]['pull_requests'][] = $pullRequest;
            }
        }

        return $users;
    }
}
