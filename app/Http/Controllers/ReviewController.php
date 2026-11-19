<?php

namespace App\Http\Controllers;

use App\Models\PullRequest;
use App\Models\PullRequestGroup;
use App\Services\Bitbucket;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    /** Sort options of the list, the first being the default. */
    public const SORTS = [
        'updated' => 'Recently updated',
        'files-asc' => 'Fewest files',
        'files-desc' => 'Most files',
        'lines-asc' => 'Fewest lines',
        'lines-desc' => 'Most lines',
    ];

    public function index(Request $request, Bitbucket $bitbucket): View
    {
        $reviewer = $request->query('reviewer');
        $allPullRequests = $bitbucket->getAllPullRequests();

        // Workspaces and repositories with open pull requests, with how many, for the filters.
        $workspaces = [];
        $repositories = [];
        foreach ($allPullRequests as $pullRequest) {
            $workspaces[$pullRequest->getWorkspace()] = ($workspaces[$pullRequest->getWorkspace()] ?? 0) + 1;
            $repositories[$pullRequest->getRepository()] = ($repositories[$pullRequest->getRepository()] ?? 0) + 1;
        }
        ksort($workspaces, SORT_NATURAL | SORT_FLAG_CASE);
        ksort($repositories, SORT_NATURAL | SORT_FLAG_CASE);

        // Unknown values (a repository whose pull requests are all closed now) are ignored.
        $workspace = array_key_exists((string) $request->query('workspace'), $workspaces) ? $request->query('workspace') : null;
        $repository = array_key_exists((string) $request->query('repository'), $repositories) ? $request->query('repository') : null;
        if ($workspace !== null && $repository !== null && !str_starts_with($repository, "$workspace/")) {
            $repository = null;
        }
        if ($workspace !== null) {
            $repositories = array_filter($repositories, fn($name) => str_starts_with($name, "$workspace/"), ARRAY_FILTER_USE_KEY);
        }

        // Workspace, repository and author filters, before the reviewer one: the reviewer counters count within them.
        $pullRequests = array_values(array_filter($allPullRequests, fn(PullRequest $pullRequest) =>
            ($workspace === null || $pullRequest->getWorkspace() === $workspace)
            && ($repository === null || $pullRequest->getRepository() === $repository)
        ));

        // Authors of the pull requests within the workspace and repository filters, with how many.
        $authors = [];
        foreach ($pullRequests as $pullRequest) {
            $author = $pullRequest->getAuthor();
            $authors[$author['uuid']] ??= ['user' => $author, 'count' => 0];
            $authors[$author['uuid']]['count']++;
        }
        uasort($authors, fn($a, $b) => strnatcasecmp(self::userName($a['user']), self::userName($b['user'])));

        $author = array_key_exists((string) $request->query('author'), $authors) ? $request->query('author') : null;
        if ($author !== null) {
            $pullRequests = array_values(array_filter($pullRequests, fn(PullRequest $pullRequest) => $pullRequest->getAuthor()['uuid'] === $author));
        }
        $users = $bitbucket->getUsersWithAssignedPullRequests($bitbucket->getAllUsers(), $pullRequests);
        // The counters show work items (Jira tickets; a pull request without one counts on its own), like the list.
        foreach ($users as $key => $user) {
            $users[$key]['work_items'] = count(PullRequestGroup::fromPullRequests($user['pull_requests']));
        }

        if ($reviewer) {
            $pullRequests = array_filter($pullRequests, function ($pullRequest) use ($reviewer) {
                foreach ($pullRequest->getAssignees() as $assignee) {
                    if ($assignee['uuid'] === $reviewer) {
                        return true;
                    }
                }

                return false;
            });
        }

        $sort = array_key_exists((string) $request->query('sort'), self::SORTS) ? $request->query('sort') : array_key_first(self::SORTS);

        return view('reviews.index', [
            'users' => $users,
            'pullRequests' => $pullRequests,
            'groups' => self::sortGroups(PullRequestGroup::fromPullRequests($pullRequests), $sort),
            'workspaces' => $workspaces,
            'repositories' => $repositories,
            'authors' => $authors,
            'sorts' => self::SORTS,
            'sort' => $sort,
            // Kept on every link of the page; the default sort stays out of the URL.
            'filters' => array_filter([
                'workspace' => $workspace,
                'repository' => $repository,
                'author' => $author,
                'sort' => $sort === array_key_first(self::SORTS) ? null : $sort,
            ]),
            'reviewer' => $reviewer,
        ]);
    }

    /**
     * Groups come most recently updated first. By size, groups whose size isn't synced yet go last;
     * equal sizes keep the most recently updated first (usort is stable).
     *
     * @param PullRequestGroup[] $groups
     * @return PullRequestGroup[]
     */
    protected static function sortGroups(array $groups, string $sort): array
    {
        if ($sort === 'updated') {
            return $groups;
        }

        [$field, $direction] = explode('-', $sort);
        usort($groups, function (PullRequestGroup $a, PullRequestGroup $b) use ($field, $direction) {
            $sizeA = $a->getDiffSize()[$field] ?? null;
            $sizeB = $b->getDiffSize()[$field] ?? null;
            if ($sizeA === null || $sizeB === null) {
                return ($sizeA === null) <=> ($sizeB === null);
            }

            return $direction === 'asc' ? $sizeA <=> $sizeB : $sizeB <=> $sizeA;
        });

        return $groups;
    }

    protected static function userName(array $user): string
    {
        return $user['display_name'] ?? $user['nickname'] ?? '';
    }
}
