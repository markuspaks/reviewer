<?php

namespace App\Mcp\Tools;

use App\Mcp\ReviewContext;
use App\Models\DraftComment;
use App\Services\BitbucketUserApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description(<<<'TEXT'
    Comments on a pull request: the discussion in Bitbucket (threads with file and line) and the unsent comments already
    saved in Reviewer, including ones from other agents. Read this before adding comments, so you don't repeat them;
    reply to a Bitbucket comment with add_review_comment and its parent_id.
    TEXT)]
class GetPullRequestComments extends Tool
{
    protected string $name = 'get_pull_request_comments';

    public function handle(Request $request): Response
    {
        $data = $request->validate(PullRequestArguments::rules());
        $id = (int) $data['pull_request_id'];
        if (ReviewContext::findPullRequest($data['repository'], $id) === null) {
            return Response::error(PullRequestArguments::notFound($data['repository'], $id));
        }
        [$workspace, $repository] = explode('/', $data['repository'], 2);

        try {
            $comments = BitbucketUserApi::withAppCredentials()->getComments($workspace, $repository, $id);
        } catch (RequestException|ConnectionException $e) {
            return Response::error('Bitbucket: ' . (($e instanceof RequestException ? $e->response->json('error.message') : null) ?? $e->getMessage()));
        }

        $out = $this->bitbucketThreads($comments);

        $drafts = DraftComment::for($request->user()->uuid, $workspace, $repository, $id)->orderBy('id')->get();
        $out .= "\n\n## Unsent comments in Reviewer (" . $drafts->count() . ")\n";
        $out .= $drafts->isEmpty() ? "\nNone." : '';
        foreach ($drafts as $draft) {
            $where = $draft->parent_id ? "reply to comment {$draft->parent_id}" : $this->location($draft->inline());
            $out .= "\n- draft {$draft->id} by " . ($draft->author_name ?? 'the reviewer') . " — $where:\n" . $this->indent($draft->content);
        }

        return Response::text($out);
    }

    protected function bitbucketThreads(array $comments): string
    {
        $byId = [];
        foreach ($comments as $comment) {
            if (!($comment['deleted'] ?? false)) {
                $byId[$comment['id']] = $comment;
            }
        }
        $rootOf = function (array $comment) use ($byId): int {
            $seen = [];
            while (isset($comment['parent']['id'], $byId[$comment['parent']['id']]) && !isset($seen[$comment['id']])) {
                $seen[$comment['id']] = true;
                $comment = $byId[$comment['parent']['id']];
            }
            return $comment['id'];
        };
        $threads = [];
        foreach ($byId as $id => $comment) {
            $threads[$rootOf($comment)][] = $comment;
        }

        $out = "## Comments in Bitbucket (" . count($byId) . ")\n";
        if (!$threads) {
            return $out . "\nNone.";
        }
        foreach ($threads as $rootId => $thread) {
            usort($thread, fn($a, $b) => strcmp($a['created_on'], $b['created_on']));
            $root = $byId[$rootId];
            $outdated = ($root['inline']['outdated'] ?? false) ? ' (outdated: the code changed since)' : '';
            $out .= "\n### " . $this->location($root['inline'] ?? null) . "$outdated\n";
            foreach ($thread as $comment) {
                $out .= "\n- comment {$comment['id']} by " . ($comment['user']['display_name'] ?? '?')
                    . ', ' . Carbon::parse($comment['created_on'])->toDateTimeString()
                    . (($comment['pending'] ?? false) ? ' (pending draft)' : '') . ":\n"
                    . $this->indent($comment['content']['raw'] ?? '');
            }
        }

        return $out;
    }

    protected function location(?array $inline): string
    {
        if (!$inline) {
            return 'On the pull request';
        }
        $line = isset($inline['to']) ? "line {$inline['to']} (new)" : (isset($inline['from']) ? "line {$inline['from']} (old)" : 'whole file');

        return "`{$inline['path']}` $line";
    }

    protected function indent(string $text): string
    {
        return preg_replace('/^/m', '  ', rtrim($text));
    }

    public function schema(JsonSchema $schema): array
    {
        return PullRequestArguments::schema($schema);
    }
}
