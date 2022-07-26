<?php

namespace App\Http\Services;

use App\Models\PullRequest;
use App\Models\User;
use GrahamCampbell\Bitbucket\BitbucketManager;
use Http\Client\Exception;

class Bitbucket
{
    protected BitbucketManager $bitbucket;

    protected ?array $currentUser = null;

    protected ?array $pullRequests = null;

    protected ?array $users = null;

    protected ?array $workspaces = null;

    public function __construct(BitbucketManager $bitbucketManager)
    {
        $this->bitbucket = $bitbucketManager;
    }

    /**
     * @throws Exception
     */
    public function getCurrentUser(): array
    {
        if ($this->currentUser !== null) {
            return $this->currentUser;
        }

        return $this->currentUser = $this->bitbucket->currentUser()->show();
    }

    /**
     * @return PullRequest[]
     * @throws Exception
     */
    public function getAllPullRequests(): array
    {
        if ($this->pullRequests !== null) {
            return $this->pullRequests;
        }

        $users = $this->getAllUsers();

        $pullRequests = [];
        foreach ($users as $user) {
            foreach ($this->bitbucket->pullRequests()->list($user['account_id'], ['pagelen' => 50])['values'] as $pullRequestWithoutReviewers) {
                [$workspace, $repository] = explode('/', $pullRequestWithoutReviewers['source']['repository']['full_name']);
                $pullRequest = $this->bitbucket->repositories()->workspaces($workspace)->pullRequests($repository)->show($pullRequestWithoutReviewers['id']);
                $pullRequests[] = new PullRequest($this, $pullRequest);
            }
        }

        usort($pullRequests, function ($a, $b) {
            if ($a->getData()['updated_on'] < $b->getData()['updated_on']) {
                return 1;
            }
            return -1;
        });

        return $this->pullRequests = $pullRequests;
    }

    public function getAllUsers(?array $uuidsFilter = null): array
    {
        if ($this->users !== null) {
            return $this->users;
        }

        $users = [];
        foreach ($this->getAllWorkspaces() as $workspace) {

            /** @noinspection PhpUnhandledExceptionInspection */
            foreach ($this->bitbucket->workspaces($workspace['slug'])->members()->list()['values'] as $userData) {
                $uuid = $userData['user']['uuid'];

                if ($uuidsFilter !== null && !in_array($uuid, $uuidsFilter)) {
                    continue;
                }

                $userData['user']['email'] = User::find(str_replace(['{', '}'], '', $uuid))->email ?? null;
                $users[$uuid] = $userData['user'];
            }
        }

        return $this->users = $users;
    }

    /**
     * @throws Exception
     */
    public function getUsersWithAssignedPullRequests(array $users): array
    {
        foreach ($users as $user) {
            $user['pull_requests'] = [];
        }
        foreach ($this->getAllPullRequests() as $pullRequest) {
            foreach ($pullRequest->getAssignees() as $assignee) {
                if (!isset($users[$assignee['uuid']])) {
                    continue;
                }
                $users[$assignee['uuid']]['pull_requests'][] = $pullRequest;
            }
        }

        return $users;
    }

    public function getAllWorkspaces(): array
    {
        if ($this->workspaces !== null) {
            return $this->workspaces;
        }

        /** @noinspection PhpUnhandledExceptionInspection */
        return $this->workspaces = $this->bitbucket->currentUser()->listWorkspaces()['values'];
    }

    /**
     * @throws Exception
     */
    public function getComments(PullRequest $pullRequest): array
    {
        $pullRequestData = $pullRequest->getData();
        [$workspace, $repository] = explode('/', $pullRequestData['source']['repository']['full_name']);
        return $this->bitbucket->repositories()->workspaces($workspace)->pullRequests($repository)->comments($pullRequestData['id'])->list()['values'];
    }
}
