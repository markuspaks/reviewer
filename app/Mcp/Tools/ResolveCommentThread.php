<?php

namespace App\Mcp\Tools;

use App\Mcp\ReviewContext;
use App\Services\BitbucketUserApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description(<<<'TEXT'
    Resolve a comment thread in Bitbucket, or reopen it with resolved false. Unlike comments, which are saved unsent,
    this acts in Bitbucket at once, as the reviewer (with their Bitbucket token): use it when the reviewer asks you
    to resolve or reopen a thread, not on your own judgement. Pass the id of the thread's first comment (from
    get_pull_request_comments; a reply can't be resolved on its own). Unsent comments in Reviewer and pending draft
    comments have no thread to resolve.
    TEXT)]
class ResolveCommentThread extends Tool
{
    protected string $name = 'resolve_comment_thread';

    public function handle(Request $request): Response
    {
        $data = $request->validate(PullRequestArguments::rules() + [
            'comment_id' => ['required', 'integer', 'min:1'],
            'resolved' => ['nullable', 'boolean'],
        ]);
        $id = (int) $data['pull_request_id'];
        if (ReviewContext::findPullRequest($data['repository'], $id) === null) {
            return Response::error(PullRequestArguments::notFound($data['repository'], $id));
        }
        if (!$request->user()->hasBitbucketToken()) {
            return Response::error('Threads are resolved in Bitbucket as the reviewer, but they have no Bitbucket API token '
                . 'saved in Reviewer. Ask them to add it on the Configuration page: ' . url('configuration') . '#bitbucket');
        }

        [$workspace, $repository] = explode('/', $data['repository'], 2);
        $commentId = (int) $data['comment_id'];
        $resolve = (bool) ($data['resolved'] ?? true);
        $api = new BitbucketUserApi();

        try {
            $comment = $api->getComment($workspace, $repository, $id, $commentId);
            if (isset($comment['parent']['id'])) {
                return Response::error("Comment $commentId is a reply (to comment {$comment['parent']['id']}); only a thread's first "
                    . 'comment can be resolved. Find it with get_pull_request_comments (the first comment listed in the thread).');
            }
            if ($comment['pending'] ?? false) {
                return Response::error("Comment $commentId is a pending draft comment, not published yet; it has no thread to resolve.");
            }
            try {
                $api->resolveComment($workspace, $repository, $id, $commentId, $resolve);
            } catch (RequestException $e) {
                // 409: already resolved. 404 on reopening: not resolved. Either way the thread is in the wanted state.
                $status = $e->response->status();
                if (!($resolve ? $status === 409 : $status === 404)) {
                    throw $e;
                }
            }
            $comment = $api->getComment($workspace, $repository, $id, $commentId);
        } catch (RequestException|ConnectionException $e) {
            if ($e instanceof RequestException && $e->response->status() === 404) {
                return Response::error("No comment $commentId on {$data['repository']} #$id in Bitbucket (deleted, or another pull request?). "
                    . 'List the threads with get_pull_request_comments.');
            }

            return Response::error('Bitbucket: ' . (($e instanceof RequestException ? $e->response->json('error.message') : null) ?? $e->getMessage()));
        }

        $resolution = $comment['resolution'] ?? null;
        if ($resolution !== null) {
            $by = array_filter([
                isset($resolution['user']['display_name']) ? "by {$resolution['user']['display_name']}" : null,
                isset($resolution['created_on']) ? Carbon::parse($resolution['created_on'])->toDateTimeString() . ' UTC' : null,
            ]);

            return Response::text("Resolved the thread of comment $commentId on {$data['repository']} #$id"
                . ($by ? ' (' . implode(', ', $by) . ')' : '') . '.');
        }

        return Response::text("Reopened the thread of comment $commentId on {$data['repository']} #$id.");
    }

    public function schema(JsonSchema $schema): array
    {
        return PullRequestArguments::schema($schema) + [
            'comment_id' => $schema->integer()->description('Bitbucket id of the thread\'s first comment, from get_pull_request_comments ("comment N").')->required(),
            'resolved' => $schema->boolean()->description('true (default) to resolve the thread, false to reopen it.'),
        ];
    }
}
