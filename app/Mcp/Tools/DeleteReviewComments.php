<?php

namespace App\Mcp\Tools;

use App\Models\DraftComment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description(<<<'TEXT'
    Delete unsent agent comments in Reviewer (drafts from get_pull_request_comments, "draft N by <model>"), e.g. findings
    that turned out wrong or duplicates. Only agents' unsent comments can be deleted: not the reviewer's own, and not
    comments already sent to Bitbucket.
    TEXT)]
class DeleteReviewComments extends Tool
{
    protected string $name = 'delete_review_comments';

    public function handle(Request $request): Response
    {
        $data = $request->validate(PullRequestArguments::rules() + [
            'draft_ids' => ['required', 'array', 'min:1', 'max:100'],
            'draft_ids.*' => ['integer', 'min:1', 'distinct'],
        ], [
            'draft_ids.required' => 'Pass draft_ids: the numbers of the drafts to delete, from get_pull_request_comments.',
        ]);

        [$workspace, $repository] = explode('/', $data['repository'], 2);
        $id = (int) $data['pull_request_id'];
        $ids = array_map('intval', $data['draft_ids']);
        $drafts = DraftComment::for($request->user()->uuid, $workspace, $repository, $id)->whereIn('id', $ids)->get()->keyBy('id');

        $deleted = [];
        $lines = [];
        foreach ($ids as $draftId) {
            $draft = $drafts->get($draftId);
            if ($draft === null) {
                $lines[] = "- draft $draftId: not found on {$data['repository']} #$id (already sent or deleted?)";
            } elseif ($draft->author_name === null) {
                $lines[] = "- draft $draftId: kept, the reviewer wrote it and only they can delete it";
            } else {
                $draft->delete();
                $deleted[] = $draftId;
            }
        }

        $summary = $deleted
            ? 'Deleted ' . count($deleted) . ' unsent ' . (count($deleted) === 1 ? 'comment' : 'comments') . ': draft ' . implode(', ', $deleted) . '.'
            : 'Deleted nothing.';
        $text = $summary . ($lines ? "\n" . implode("\n", $lines) : '');

        return $deleted ? Response::text($text) : Response::error($text);
    }

    public function schema(JsonSchema $schema): array
    {
        return PullRequestArguments::schema($schema) + [
            'draft_ids' => $schema->array()->items($schema->integer())
                ->description('Numbers of the drafts to delete, from get_pull_request_comments ("draft N"). Up to 100.')
                ->required(),
        ];
    }
}
