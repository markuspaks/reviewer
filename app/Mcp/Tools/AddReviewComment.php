<?php

namespace App\Mcp\Tools;

use App\Mcp\ReviewContext;
use App\Models\DraftComment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description(<<<'TEXT'
    Add a review comment to a pull request. It is saved in Reviewer as an unsent comment, with your model name and effort
    as its author; the human reviewer checks it and decides whether to send it to Bitbucket. Nothing is posted to Bitbucket.
    Comment on a line (path + line + side), on a whole file (path only), on the pull request (no path), or reply to a
    Bitbucket comment (parent_id). Write one comment per issue, in Markdown.
    TEXT)]
class AddReviewComment extends Tool
{
    protected string $name = 'add_review_comment';

    public function handle(Request $request): Response
    {
        $data = $request->validate(PullRequestArguments::rules() + [
            'content' => ['required', 'string', 'max:32000'],
            ...self::authorRules(),
            'path' => ['nullable', 'string', 'max:1000', 'required_with:line'],
            'line' => ['nullable', 'integer', 'min:1'],
            'side' => ['nullable', 'in:new,old'],
            'parent_id' => ['nullable', 'integer', 'min:1'],
        ], self::authorMessages());
        $id = (int) $data['pull_request_id'];
        $snapshot = ReviewContext::findPullRequest($data['repository'], $id);
        if ($snapshot === null) {
            return Response::error(PullRequestArguments::notFound($data['repository'], $id));
        }

        $isReply = isset($data['parent_id']);
        $line = $isReply ? null : ($data['line'] ?? null);
        $draft = DraftComment::create([
            'user_uuid' => $request->user()->uuid,
            'author_name' => self::authorName($data['model'], $data['effort']),
            'repository' => $data['repository'],
            'pull_request_id' => $id,
            'parent_id' => $data['parent_id'] ?? null,
            'path' => $isReply ? null : ($data['path'] ?? null),
            'line' => $line,
            'side' => $line === null ? null : ($data['side'] ?? 'new'),
            'content' => $data['content'],
        ]);

        [$workspace, $repository] = explode('/', $data['repository'], 2);
        $where = match (true) {
            $isReply => "as a reply to comment {$draft->parent_id}",
            $draft->line !== null => "on `{$draft->path}` line {$draft->line} ({$draft->side})",
            $draft->path !== null => "on `{$draft->path}`",
            default => 'on the pull request',
        };

        return Response::text("Saved unsent comment draft {$draft->id} $where, by {$draft->author_name}. "
            . 'The reviewer will check it before sending: ' . route('pull-requests.review', [$workspace, $repository, $id]));
    }

    /**
     * "Model (effort effort)", the author shown on an agent's comment.
     */
    public static function authorName(string $model, string $effort): string
    {
        $effort = trim(preg_replace('/\s*effort$/i', '', trim($effort)));

        return trim($model) . " ($effort effort)";
    }

    public static function authorRules(): array
    {
        return [
            'model' => ['required', 'string', 'max:100'],
            'effort' => ['required', 'string', 'max:50'],
        ];
    }

    public static function authorMessages(): array
    {
        return [
            'model.required' => 'Pass your model name, e.g. "Claude Opus 5.5". It is shown as the comment author.',
            'effort.required' => 'Pass your reasoning effort, e.g. "high" ("unknown" if you don\'t know). It is shown with the author.',
        ];
    }

    public static function authorSchema(JsonSchema $schema): array
    {
        return [
            'model' => $schema->string()->description('Your model name, e.g. "Claude Opus 5.5" or "GPT-5". Shown as the comment author.')->required(),
            'effort' => $schema->string()->description('Your reasoning effort: low, medium, high, xhigh, max, or "unknown". Shown with the author.')->required(),
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return PullRequestArguments::schema($schema) + [
            'content' => $schema->string()->description('The comment, in Markdown.')->required(),
            ...self::authorSchema($schema),
            'path' => $schema->string()->description('File path from the diff. Omit to comment on the whole pull request.'),
            'line' => $schema->integer()->description('Line number in the file. Omit (with path) to comment on the whole file.'),
            'side' => $schema->string()->enum(['new', 'old'])->description('"new" (default) for added or unchanged lines, "old" for removed lines.'),
            'parent_id' => $schema->integer()->description('Bitbucket comment id to reply to (from get_pull_request_comments). Path and line are then ignored.'),
        ];
    }
}
