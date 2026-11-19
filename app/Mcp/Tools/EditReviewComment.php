<?php

namespace App\Mcp\Tools;

use App\Models\DraftComment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description(<<<'TEXT'
    Change the text of an unsent agent comment in Reviewer (a draft from get_pull_request_comments, "draft N by <model>").
    You become its author. Only agents' unsent comments can be changed: not the reviewer's own, and not comments already
    sent to Bitbucket. To move a comment to another line, delete it and add it again.
    TEXT)]
class EditReviewComment extends Tool
{
    protected string $name = 'edit_review_comment';

    public function handle(Request $request): Response
    {
        $data = $request->validate(PullRequestArguments::rules() + AddReviewComment::authorRules() + [
            'draft_id' => ['required', 'integer', 'min:1'],
            'content' => ['required', 'string', 'max:32000'],
        ], AddReviewComment::authorMessages());

        [$workspace, $repository] = explode('/', $data['repository'], 2);
        $id = (int) $data['pull_request_id'];
        $draft = DraftComment::for($request->user()->uuid, $workspace, $repository, $id)->find($data['draft_id']);

        if ($draft === null) {
            return Response::error("No unsent comment draft {$data['draft_id']} on {$data['repository']} #$id. "
                . 'It may have been sent or deleted already; list the drafts with get_pull_request_comments.');
        }
        if ($draft->author_name === null) {
            return Response::error("Draft {$draft->id} was written by the reviewer, and only they can change it. "
                . 'Add your suggestion as a separate comment, or as a reply, instead.');
        }

        $previousAuthor = $draft->author_name;
        $draft->update([
            'content' => $data['content'],
            'author_name' => AddReviewComment::authorName($data['model'], $data['effort']),
        ]);

        return Response::text("Updated unsent comment draft {$draft->id}"
            . ($previousAuthor !== $draft->author_name ? " (was by $previousAuthor)" : '')
            . ", now by {$draft->author_name}.");
    }

    public function schema(JsonSchema $schema): array
    {
        return PullRequestArguments::schema($schema) + [
            'draft_id' => $schema->integer()->description('The draft number, from get_pull_request_comments ("draft N").')->required(),
            'content' => $schema->string()->description('The new comment text, in Markdown. Replaces the old text.')->required(),
            ...AddReviewComment::authorSchema($schema),
        ];
    }
}
