<?php

namespace App\Http\Controllers;

use App\Models\BitbucketUser;
use App\Models\DraftComment;
use App\Models\PullRequest;
use App\Models\PullRequestSnapshot;
use App\Models\ViewedFile;
use App\Services\BitbucketUserApi;
use App\Services\Jira;
use App\Support\Diff;
use App\Support\ReviewComments;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Review a pull request: file tree, diff and comments, read live from Bitbucket as the logged-in user.
 *
 * New comments are saved locally (DraftComment) until the user sends them all at once to their draft review in
 * Bitbucket, where they stay pending until the user finishes the review there. Pending comments can be edited here too.
 */
class PullRequestReviewController extends Controller
{
    protected const TOKEN_REJECTED = 'Bitbucket rejected your API token: it may have expired or been revoked. Add a new one on the Configuration page.';

    /** Files with more diff lines than this start collapsed. */
    protected const COLLAPSE_LINES = 600;

    /** Larger files are not shown in full. */
    protected const FULL_FILE_BYTES = 2_000_000;

    public function __construct(protected BitbucketUserApi $api)
    {
    }

    public function show(string $workspace, string $repository, int $id, Jira $jira): View|RedirectResponse
    {
        try {
            $pullRequest = $this->api->getPullRequest($workspace, $repository, $id);
            $files = Diff::sortByTree(Diff::parse($this->api->getDiff($workspace, $repository, $id)));
            $comments = new ReviewComments([
                ...$this->api->getComments($workspace, $repository, $id),
                ...$this->unsentComments($workspace, $repository, $id),
            ], $files);
        } catch (RequestException $e) {
            if ($e->response->status() === 401) {
                // Revoked or expired API token.
                return redirect()->to(url('configuration') . '#bitbucket')->with('warning', self::TOKEN_REJECTED);
            }
            abort($e->response->status() === 404 ? 404 : 502, 'Bitbucket: ' . ($e->response->json('error.message') ?? $e->getMessage()));
        }

        // Files the user marked as viewed: still viewed if unchanged since, otherwise flagged as changed.
        $seenVersions = ViewedFile::versions($this->currentUserUuid(), $workspace, $repository, $id);
        foreach ($files as &$file) {
            $seen = $seenVersions[$file['path']] ?? null;
            $file['viewed'] = $seen !== null && $seen === $file['version'];
            $file['changed_since_viewed'] = $seen !== null && $seen !== $file['version'];
        }
        unset($file);

        $snapshot = PullRequestSnapshot::where('repository', "$workspace/$repository")->where('pull_request_id', $id)->first();
        $issueKey = Jira::findIssueKey($pullRequest['title'] ?? null, $pullRequest['source']['branch']['name'] ?? null);

        return view('pull-requests.review', [
            'workspace' => $workspace,
            'repository' => $repository,
            'pullRequest' => $pullRequest,
            'files' => $files,
            'tree' => Diff::tree($files),
            'comments' => $comments,
            'collapseLines' => self::COLLAPSE_LINES,
            'lastPipeline' => $snapshot?->last_pipeline,
            'issueKey' => $issueKey,
            'issueUrl' => $issueKey ? $jira->getIssueUrl($issueKey, $workspace) : null,
            'issueSummary' => $issueKey ? $jira->getIssueSummary($issueKey) : null,
            'currentUserUuid' => $this->currentUserUuid(),
        ]);
    }

    /**
     * The new version of a file (at the pull request's source commit the page was loaded with), as lines, for showing
     * the whole file around the diff.
     */
    public function fileContent(Request $request, string $workspace, string $repository, int $id): JsonResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:4000'],
            'commit' => ['required', 'string', 'regex:/^[0-9a-f]{7,40}$/i'],
            // The source repository: a fork for pull requests from one.
            'repository' => ['required', 'string', 'regex:#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#'],
        ]);

        try {
            $response = $this->api->getFileResponse($data['repository'], $data['commit'], $data['path']);
            if ($response->status() === 404) {
                return response()->json(['message' => 'Bitbucket: the file was not found at the pull request\'s source commit.'], 404);
            }
            $response->throw();
        } catch (RequestException $e) {
            return $this->apiError($e);
        }

        $content = $response->body();
        if (strlen($content) > self::FULL_FILE_BYTES) {
            return response()->json(['message' => 'The file is too large to show in full.'], 422);
        }
        if (str_contains($content, "\0")) {
            return response()->json(['message' => 'Binary file, not shown.'], 422);
        }

        $lines = explode("\n", str_replace("\r\n", "\n", $content));
        if (end($lines) === '') {
            array_pop($lines);
        }

        return response()->json(['lines' => $lines], 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Save an unsent comment: on the pull request, on a file, on a diff line, or as a reply to a Bitbucket comment.
     */
    public function storeDraft(Request $request, string $workspace, string $repository, int $id): JsonResponse
    {
        $data = $request->validate([
            'content' => ['required', 'string', 'max:32000'],
            'parent_id' => ['nullable', 'integer'],
            'path' => ['nullable', 'string', 'max:1000', 'required_with:line'],
            // A path without a line is a comment on the whole file.
            'line' => ['nullable', 'integer', 'min:1'],
            'side' => ['nullable', 'in:old,new', 'required_with:line'],
        ]);

        $draft = DraftComment::create([
            'user_uuid' => trim($this->currentUserUuid(), '{}'),
            'repository' => "$workspace/$repository",
            'pull_request_id' => $id,
            'parent_id' => $data['parent_id'] ?? null,
            // A reply takes its place from the comment it replies to.
            'path' => isset($data['parent_id']) ? null : ($data['path'] ?? null),
            'line' => isset($data['parent_id']) ? null : ($data['line'] ?? null),
            'side' => isset($data['parent_id']) || !isset($data['line']) ? null : $data['side'],
            'content' => $data['content'],
        ]);
        $comment = $draft->toCommentArray($this->currentBitbucketUser());

        $view = $draft->parent_id !== null
            ? view('pull-requests.partials.comment', ['comment' => $comment, 'currentUserUuid' => $this->currentUserUuid()])
            : view('pull-requests.partials.thread', [
                'thread' => ['root' => $comment, 'replies' => []] + ($draft->path !== null && $draft->line === null ? ['label' => 'On the file'] : []),
                'currentUserUuid' => $this->currentUserUuid(),
            ]);

        return response()->json(['id' => $comment['id'], 'html' => $view->render()]);
    }

    public function updateDraft(Request $request, string $workspace, string $repository, int $id, int $draft): JsonResponse
    {
        $data = $request->validate(['content' => ['required', 'string', 'max:32000']]);

        $model = $this->findDraft($workspace, $repository, $id, $draft);
        $model->update(['content' => $data['content']]);

        return response()->json([
            'id' => "draft-{$model->id}",
            'html' => view('pull-requests.partials.comment', [
                'comment' => $model->toCommentArray($this->currentBitbucketUser()),
                'currentUserUuid' => $this->currentUserUuid(),
            ])->render(),
        ]);
    }

    public function destroyDraft(string $workspace, string $repository, int $id, int $draft): JsonResponse
    {
        $this->findDraft($workspace, $repository, $id, $draft)->delete();

        return response()->json(['deleted' => $draft]);
    }

    /**
     * Send all the user's unsent comments on this pull request to Bitbucket, oldest first, as pending comments of their
     * draft review there: still visible only to them until they finish the review in Bitbucket.
     * Each one is removed here as soon as Bitbucket has it, so a failure part way leaves only the rest to send again.
     */
    public function publish(string $workspace, string $repository, int $id): JsonResponse
    {
        $lock = Cache::lock('publish-drafts:' . $this->currentUserUuid() . ":$workspace/$repository/$id", 600);
        if (!$lock->get()) {
            return response()->json(['message' => 'Your comments are already being sent. Reload the page in a moment.'], 409);
        }

        set_time_limit(600);
        $sent = 0;
        try {
            $drafts = DraftComment::for($this->currentUserUuid(), $workspace, $repository, $id)->orderBy('id')->get();
            foreach ($drafts as $draft) {
                try {
                    $this->api->createComment($workspace, $repository, $id, $draft->content, $draft->inline(), $draft->parent_id, pending: true);
                } catch (RequestException $e) {
                    $error = $this->apiError($e);
                    $reason = $error->getData(true)['message'];
                    $message = ($sent ? "Sent $sent of {$drafts->count()}. " : '') . rtrim($reason, '.') . '.'
                        . ' The comments not sent are still saved here.';

                    return response()->json(['sent' => $sent, 'message' => $message], $error->status());
                }
                $draft->delete();
                $sent++;
            }
        } finally {
            $lock->release();
        }

        return response()->json(['sent' => $sent]);
    }

    /**
     * Mark a file as viewed (at the version of its diff the user saw) or not viewed.
     */
    public function markViewed(Request $request, string $workspace, string $repository, int $id): JsonResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:4000'],
            'version' => ['required', 'string', 'max:40'],
            'viewed' => ['required', 'boolean'],
        ]);
        $key = [
            'user_uuid' => trim($this->currentUserUuid(), '{}'),
            'repository' => "$workspace/$repository",
            'pull_request_id' => $id,
            'path_hash' => sha1($data['path']),
        ];

        if ($data['viewed']) {
            ViewedFile::updateOrCreate($key, ['path' => $data['path'], 'version' => $data['version']]);
        } else {
            ViewedFile::where($key)->delete();
        }

        return response()->json(['viewed' => (bool) $data['viewed']]);
    }

    /**
     * Approve, request changes, or neither (state "none"), as the user. Bitbucket keeps the two apart, so the one the
     * user had is taken back first. Returns the new heading parts; the overview gets the new participants too.
     */
    public function setReviewState(Request $request, string $workspace, string $repository, int $id): JsonResponse
    {
        $data = $request->validate(['state' => ['required', 'in:approved,changes_requested,none']]);
        $wanted = $data['state'] === 'none' ? null : $data['state'];

        try {
            $pullRequest = $this->api->getPullRequest($workspace, $repository, $id);
            $current = $this->ownParticipantState($pullRequest);

            if ($current !== $wanted) {
                if ($current === PullRequest::STATE_APPROVED) {
                    $this->api->approve($workspace, $repository, $id, false);
                } elseif ($current === PullRequest::STATE_CHANGES_REQUESTED) {
                    $this->api->requestChanges($workspace, $repository, $id, false);
                }

                if ($wanted === PullRequest::STATE_APPROVED) {
                    $this->api->approve($workspace, $repository, $id);
                } elseif ($wanted === PullRequest::STATE_CHANGES_REQUESTED) {
                    $this->api->requestChanges($workspace, $repository, $id);
                }

                $pullRequest = $this->api->getPullRequest($workspace, $repository, $id);
                $this->updateSnapshotParticipants($workspace, $repository, $id, $pullRequest);
            }
        } catch (RequestException $e) {
            return $this->apiError($e);
        }

        return response()->json([
            'state' => $this->ownParticipantState($pullRequest),
            'actions' => view('pull-requests.partials.review-actions', [
                'pullRequest' => $pullRequest,
                'currentUserUuid' => $this->currentUserUuid(),
            ])->render(),
            'participants' => view('pull-requests.partials.participants', ['pullRequest' => $pullRequest])->render(),
        ]);
    }

    /**
     * The user's approved / changes_requested state on the pull request, or null.
     */
    protected function ownParticipantState(array $pullRequest): ?string
    {
        foreach ($pullRequest['participants'] ?? [] as $participant) {
            if (($participant['user']['uuid'] ?? null) === $this->currentUserUuid()) {
                return $participant['state'] ?? null;
            }
        }

        return null;
    }

    /**
     * So the overview shows the new state before the next sync.
     */
    protected function updateSnapshotParticipants(string $workspace, string $repository, int $id, array $pullRequest): void
    {
        $snapshot = PullRequestSnapshot::where('repository', "$workspace/$repository")->where('pull_request_id', $id)->first();
        if ($snapshot) {
            $snapshot->update(['data' => ['participants' => $pullRequest['participants'] ?? []] + $snapshot->data]);
        }
    }

    /**
     * Resolve a comment thread in Bitbucket, or reopen it. Someone else may have done it already, which counts as done.
     * Returns the thread's new resolution line.
     */
    public function setResolved(Request $request, string $workspace, string $repository, int $id, int $comment): JsonResponse
    {
        $resolve = $request->validate(['resolved' => ['required', 'boolean']])['resolved'];

        try {
            try {
                $this->api->resolveComment($workspace, $repository, $id, $comment, (bool) $resolve);
            } catch (RequestException $e) {
                // 409: already resolved. 404 on reopening: not resolved (or gone, which the read below reports).
                $status = $e->response->status();
                if (!($resolve ? $status === 409 : $status === 404)) {
                    throw $e;
                }
            }
            $root = $this->api->getComment($workspace, $repository, $id, $comment);
        } catch (RequestException $e) {
            return $this->apiError($e);
        }

        return response()->json([
            'resolved' => isset($root['resolution']),
            'html' => view('pull-requests.partials.thread-resolution', ['root' => $root])->render(),
        ]);
    }

    /**
     * Edit a pending comment in the user's draft review in Bitbucket.
     */
    public function update(Request $request, string $workspace, string $repository, int $id, int $comment): JsonResponse
    {
        $data = $request->validate(['content' => ['required', 'string', 'max:32000']]);

        try {
            $this->ensureOwnDraft($workspace, $repository, $id, $comment);
            $updated = $this->api->updateComment($workspace, $repository, $id, $comment, $data['content']);
        } catch (RequestException $e) {
            return $this->apiError($e);
        }

        return response()->json([
            'id' => $updated['id'],
            'html' => view('pull-requests.partials.comment', ['comment' => $updated, 'currentUserUuid' => $this->currentUserUuid()])->render(),
        ]);
    }

    public function destroy(string $workspace, string $repository, int $id, int $comment): JsonResponse
    {
        try {
            $this->ensureOwnDraft($workspace, $repository, $id, $comment);
            $this->api->deleteComment($workspace, $repository, $id, $comment);
        } catch (RequestException $e) {
            return $this->apiError($e);
        }

        return response()->json(['deleted' => $comment]);
    }

    /**
     * Only the user's own drafts can be changed here; published comments are edited in Bitbucket.
     */
    protected function ensureOwnDraft(string $workspace, string $repository, int $id, int $commentId): void
    {
        $comment = $this->api->getComment($workspace, $repository, $id, $commentId);
        abort_unless(
            ($comment['pending'] ?? false) && ($comment['user']['uuid'] ?? null) === $this->currentUserUuid(),
            403,
            'Only your own draft comments can be changed here.'
        );
    }

    protected function findDraft(string $workspace, string $repository, int $id, int $draft): DraftComment
    {
        return DraftComment::for($this->currentUserUuid(), $workspace, $repository, $id)->findOrFail($draft);
    }

    /**
     * The user's unsent comments, shaped like Bitbucket comments.
     */
    protected function unsentComments(string $workspace, string $repository, int $id): array
    {
        $user = $this->currentBitbucketUser();

        return DraftComment::for($this->currentUserUuid(), $workspace, $repository, $id)
            ->orderBy('id')
            ->get()
            ->map(fn(DraftComment $draft) => $draft->toCommentArray($user))
            ->all();
    }

    /**
     * The logged-in user as a Bitbucket user (name and avatar), for showing their unsent comments.
     */
    protected function currentBitbucketUser(): array
    {
        $data = BitbucketUser::find($this->currentUserUuid())?->data ?? [];

        return [
            'uuid' => $this->currentUserUuid(),
            'display_name' => $data['display_name'] ?? Auth::user()->name,
            'links' => ['avatar' => ['href' => $data['links']['avatar']['href'] ?? '']],
        ];
    }

    protected function currentUserUuid(): string
    {
        return '{' . trim(Auth::user()->uuid, '{}') . '}';
    }

    protected function apiError(RequestException $e): JsonResponse
    {
        $status = $e->response->status();
        $message = match (true) {
            $status === 401 => self::TOKEN_REJECTED,
            // Usually a token without write:pullrequest:bitbucket.
            $status === 403 => 'Bitbucket: ' . ($e->response->json('error.message') ?? 'not allowed')
                . '. If your API token lacks write:pullrequest:bitbucket, create a new one on the Configuration page.',
            default => 'Bitbucket: ' . ($e->response->json('error.message') ?? "HTTP $status"),
        };

        return response()->json(['message' => $message], in_array($status, [401, 403, 404], true) ? $status : 502);
    }
}
