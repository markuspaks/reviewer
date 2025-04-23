<?php

namespace App\Services;

use App\Models\PullRequest;
use App\Models\User;
use Bitbucket\HttpClient\Util\UriBuilder;
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

        $pullRequests = [];
        foreach ($this->bitbucket->currentUser()->listRepositoryPermissions(['pagelen' => 100])['values'] as $repositories) {
            [$workspace, $repository] = explode('/', $repositories['repository']['full_name']);
            foreach ($this->bitbucket->repositories()->workspaces($workspace)->pullRequests($repository)->list(['pagelen' => 50])['values'] as $pullRequestWithoutReviewers) {
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

    public function getAllUsers(): array
    {
        if ($this->users !== null) {
            return $this->users;
        }

        $users = [];
        foreach ($this->getAllWorkspaces() as $workspace) {

            /** @noinspection PhpUnhandledExceptionInspection */
            foreach ($this->bitbucket->workspaces($workspace['slug'])->members()->list()['values'] as $userData) {
                $uuid = $userData['user']['uuid'];
                $userData['user']['email'] = User::find(str_replace(['{', '}'], '', $uuid))->email ?? null;
                $users[$uuid] = $userData['user'];
            }
        }

        return $this->users = $users;
    }

    public function getUsers(?array $uuidsFilter = null): array
    {
        return array_filter($this->getAllUsers(), fn($key) => in_array($key, $uuidsFilter), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @throws Exception
     */
    public function getUsersWithAssignedPullRequests(array $users): array
    {
        foreach ($users as $key => $user) {
            $users[$key]['pull_requests'] = [];
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
        $response = $this->bitbucket->repositories()->workspaces($workspace)->pullRequests($repository)->comments($pullRequestData['id'])->list([
            'sort' => '-updated_on',
            'pagelen' => 100,
        ]);
        return $response['values'];
    }

    public function getLastPipeline(PullRequest $pullRequest): ?array
    {
        $pullRequestData = $pullRequest->getData();
        [$workspace, $repository] = explode('/', $pullRequestData['source']['repository']['full_name']);
        $list = $this->bitbucket->repositories()->workspaces($workspace)->pipelines($repository)->list([
            'target.branch' => $pullRequest->getData()['source']['branch']['name'],
            'sort' => '-created_on',
            'pagelen' => 1,
        ]);

        return $list['values'][0] ?? null;
    }
}
