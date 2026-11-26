<?php

namespace App\Services;

use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Live Bitbucket calls made as the logged-in user (their own Atlassian API token), for the review page:
 * the diff, comments, the user's own draft (pending) review comments, and approving or requesting changes.
 *
 * Unlike the rest of the app this does not read the local copy: drafts are only visible to their author,
 * and diffs are too big to sync for every pull request.
 */
class BitbucketUserApi
{
    /**
     * @param Closure(PendingRequest): PendingRequest|null $authenticate defaults to the logged-in user's API token
     */
    public function __construct(protected ?Closure $authenticate = null)
    {
    }

    /**
     * Calls made with the app's own Bitbucket credentials (BITBUCKET_EMAIL / BITBUCKET_API_TOKEN, as the sync uses),
     * for MCP agents, which have no Bitbucket login of their own.
     */
    public static function withAppCredentials(): static
    {
        $connection = config('bitbucket.connections.alternative');

        return static::withCredentials((string) $connection['username'], (string) $connection['password']);
    }

    /**
     * Calls with an Atlassian account email and API token (HTTP basic auth).
     */
    public static function withCredentials(string $email, string $token): static
    {
        return new static(fn(PendingRequest $request) => $request->withBasicAuth($email, $token));
    }

    /**
     * Who the credentials belong to (uuid, display_name), unthrown: for checking a token before saving it.
     */
    public function currentUserResponse(): Response
    {
        return $this->http()->timeout(15)->get('user');
    }

    public function getPullRequest(string $workspace, string $repository, int $id): array
    {
        return $this->http()->get($this->path($workspace, $repository, $id))->throw()->json();
    }

    /**
     * Unified diff of the pull request (git format).
     *
     * @param bool $ignoreWhitespace leave out changes in whitespace only, as "git diff -w" (done by Bitbucket)
     */
    public function getDiff(string $workspace, string $repository, int $id, bool $ignoreWhitespace = false): string
    {
        return $this->http()
            ->withHeaders(['Accept' => 'text/plain'])
            ->timeout(120)
            ->get($this->path($workspace, $repository, $id) . '/diff', $ignoreWhitespace ? ['ignore_whitespace' => 'true'] : [])
            ->throw()
            ->body();
    }

    /**
     * A file's raw content at a commit, unthrown: the caller checks the status (404 when the file is not there) and size.
     *
     * @param string $repositoryFullName "workspace/repository", which for a pull request from a fork is the fork
     */
    public function getFileResponse(string $repositoryFullName, string $commit, string $path): Response
    {
        $segments = implode('/', array_map('rawurlencode', explode('/', $path)));
        [$workspace, $repository] = explode('/', $repositoryFullName, 2);

        return $this->http()
            ->withHeaders(['Accept' => '*/*'])
            ->timeout(60)
            ->get('repositories/' . rawurlencode($workspace) . '/' . rawurlencode($repository) . '/src/' . rawurlencode($commit) . '/' . $segments);
    }

    /**
     * All comments, oldest first, including the user's own pending (draft) ones.
     */
    public function getComments(string $workspace, string $repository, int $id): array
    {
        $comments = [];
        $url = $this->path($workspace, $repository, $id) . '/comments';
        // The list leaves a resolved thread's "resolution" empty ({}): ask for who resolved it and when, as the
        // single-comment endpoint gives them. The "next" links carry the fields along.
        $query = ['pagelen' => 100, 'sort' => 'created_on', 'fields' => '+values.resolution.*'];
        while ($url !== null) {
            $page = $this->http()->get($url, $query)->throw()->json();
            array_push($comments, ...($page['values'] ?? []));
            $url = $page['next'] ?? null;
            $query = [];
        }

        return $comments;
    }

    public function getComment(string $workspace, string $repository, int $id, int $commentId): array
    {
        return $this->http()->get($this->path($workspace, $repository, $id) . "/comments/$commentId")->throw()->json();
    }

    /**
     * Add a comment. A pending one goes to the user's draft review in Bitbucket and stays visible only to them
     * until they finish that review in Bitbucket.
     *
     * @param array{path?: string, to?: int, from?: int}|null $inline
     */
    public function createComment(string $workspace, string $repository, int $id, string $raw, ?array $inline = null, ?int $parentId = null, bool $pending = false): array
    {
        $payload = ['content' => ['raw' => $raw], 'pending' => $pending];
        if ($parentId !== null) {
            $payload['parent'] = ['id' => $parentId];
        } elseif ($inline !== null) {
            $payload['inline'] = $inline;
        }

        return $this->http()->post($this->path($workspace, $repository, $id) . '/comments', $payload)->throw()->json();
    }

    public function updateComment(string $workspace, string $repository, int $id, int $commentId, string $raw): array
    {
        return $this->http()
            ->put($this->path($workspace, $repository, $id) . "/comments/$commentId", ['content' => ['raw' => $raw]])
            ->throw()
            ->json();
    }

    public function deleteComment(string $workspace, string $repository, int $id, int $commentId): void
    {
        $this->http()->delete($this->path($workspace, $repository, $id) . "/comments/$commentId")->throw();
    }

    /**
     * Resolve a comment thread, or reopen it. Only top-level comments can be resolved.
     */
    public function resolveComment(string $workspace, string $repository, int $id, int $commentId, bool $resolve = true): void
    {
        $url = $this->path($workspace, $repository, $id) . "/comments/$commentId/resolve";
        ($resolve ? $this->http()->post($url) : $this->http()->delete($url))->throw();
    }

    /**
     * Approve the pull request as the user, or with $approve false take the approval back.
     */
    public function approve(string $workspace, string $repository, int $id, bool $approve = true): void
    {
        $url = $this->path($workspace, $repository, $id) . '/approve';
        ($approve ? $this->http()->post($url) : $this->http()->delete($url))->throw();
    }

    /**
     * Request changes on the pull request as the user, or with $request false take the request back.
     */
    public function requestChanges(string $workspace, string $repository, int $id, bool $request = true): void
    {
        $url = $this->path($workspace, $repository, $id) . '/request-changes';
        ($request ? $this->http()->post($url) : $this->http()->delete($url))->throw();
    }

    protected function http(): PendingRequest
    {
        $request = Http::baseUrl('https://api.bitbucket.org/2.0/')->acceptJson()->timeout(60);

        if ($this->authenticate) {
            return ($this->authenticate)($request);
        }

        // The routes using this require a saved token (RequireBitbucketToken).
        $user = Auth::user();
        if (!$user?->hasBitbucketToken()) {
            throw new RuntimeException('No Bitbucket API token saved for the logged-in user.');
        }

        return $request->withBasicAuth((string) $user->bitbucketEmail(), $user->bitbucket_api_token);
    }

    protected function path(string $workspace, string $repository, int $id): string
    {
        return 'repositories/' . rawurlencode($workspace) . '/' . rawurlencode($repository) . "/pullrequests/$id";
    }
}
