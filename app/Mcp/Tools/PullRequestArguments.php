<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * The repository + pull_request_id arguments shared by the pull request tools.
 */
class PullRequestArguments
{
    public static function rules(): array
    {
        return [
            'repository' => ['required', 'string', 'regex:#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#'],
            'pull_request_id' => ['required', 'integer', 'min:1'],
        ];
    }

    public static function schema(JsonSchema $schema): array
    {
        return [
            'repository' => $schema->string()->description('Repository as workspace/repository, e.g. "acme/api" (from get_ticket).')->required(),
            'pull_request_id' => $schema->integer()->description('Pull request number in that repository.')->required(),
        ];
    }

    public static function notFound(string $repository, int $id): string
    {
        return "No open pull request $repository #$id in Reviewer. Only open pull requests are synced (every few minutes); "
            . 'check the repository and number with get_ticket.';
    }
}
