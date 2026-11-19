<?php

namespace App\Mcp\Tools;

use App\Mcp\ReviewContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description(<<<'TEXT'
    One open pull request (repository, branches, reviewers, pipeline, description) with the team's files for agents
    (global rules plus that repository's rules). Use this for a pull request without a ticket; for a ticket use get_ticket.
    TEXT)]
class GetPullRequest extends Tool
{
    protected string $name = 'get_pull_request';

    public function handle(Request $request): Response
    {
        $data = $request->validate(PullRequestArguments::rules());
        $snapshot = ReviewContext::findPullRequest($data['repository'], (int) $data['pull_request_id']);
        if ($snapshot === null) {
            return Response::error(PullRequestArguments::notFound($data['repository'], (int) $data['pull_request_id']));
        }

        return Response::text(
            ReviewContext::pullRequest($snapshot, $request->user()->uuid)
            . ($snapshot->jira_issue_key ? "\n\nTicket: {$snapshot->jira_issue_key} (get_ticket has the ticket and its other pull requests)." : '')
            . "\n\n" . ReviewContext::files([$snapshot->repository])
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return PullRequestArguments::schema($schema);
    }
}
