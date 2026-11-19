<?php

namespace App\Mcp\Tools;

use App\Mcp\ReviewContext;
use App\Services\BitbucketUserApi;
use App\Support\Diff;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description(<<<'TEXT'
    The changes of a pull request as a unified git diff, read live from Bitbucket, with a list of changed files first.
    Line numbers for add_review_comment come from the hunks: "new" side for added and unchanged lines, "old" side for removed lines.
    Large diffs are cut off; pass "paths" to read only some files.
    TEXT)]
class GetPullRequestDiff extends Tool
{
    protected string $name = 'get_pull_request_diff';

    /** Longest diff returned, in characters. */
    protected const MAX_LENGTH = 300000;

    public function handle(Request $request): Response
    {
        $data = $request->validate(PullRequestArguments::rules() + [
            'paths' => ['nullable', 'array', 'max:200'],
            'paths.*' => ['string', 'max:1000'],
        ]);
        $id = (int) $data['pull_request_id'];
        if (ReviewContext::findPullRequest($data['repository'], $id) === null) {
            return Response::error(PullRequestArguments::notFound($data['repository'], $id));
        }
        if (!config('bitbucket.connections.alternative.username') || !config('bitbucket.connections.alternative.password')) {
            return Response::error('Reviewer has no Bitbucket credentials for agents: set BITBUCKET_EMAIL and BITBUCKET_API_TOKEN in its .env.');
        }

        [$workspace, $repository] = explode('/', $data['repository'], 2);
        try {
            $diff = BitbucketUserApi::withAppCredentials()->getDiff($workspace, $repository, $id);
        } catch (RequestException|ConnectionException $e) {
            return Response::error('Bitbucket: ' . (($e instanceof RequestException ? $e->response->json('error.message') : null) ?? $e->getMessage()));
        }

        $files = Diff::parse($diff);
        $list = "## Changed files (" . count($files) . ")\n\n" . implode("\n", array_map(
            fn(array $f) => "- {$f['status']} `{$f['path']}`" . ($f['old_path'] && $f['old_path'] !== $f['path'] ? " (from `{$f['old_path']}`)" : '')
                . ($f['binary'] ? ' (binary)' : " +{$f['additions']} −{$f['deletions']}"),
            $files
        ));

        $paths = $data['paths'] ?? [];
        if ($paths) {
            $diff = $this->onlyPaths($diff, $paths);
            if ($diff === '') {
                return Response::text("$list\n\nNone of the requested paths are in this diff.");
            }
        }

        $note = '';
        if (mb_strlen($diff) > self::MAX_LENGTH) {
            $diff = mb_substr($diff, 0, self::MAX_LENGTH);
            $note = "\n\n(Diff cut off after " . self::MAX_LENGTH . ' characters. Call again with "paths" for the files not shown.)';
        }

        return Response::text("$list\n\n## Diff\n\n```diff\n" . rtrim($diff) . "\n```$note");
    }

    /**
     * Only the file sections (diff --git ...) for the given paths, old or new.
     */
    protected function onlyPaths(string $diff, array $paths): string
    {
        $sections = preg_split('/^(?=diff --git )/m', $diff, -1, PREG_SPLIT_NO_EMPTY);
        $wanted = array_flip($paths);
        $kept = array_filter($sections, function (string $section) use ($wanted) {
            foreach (Diff::parse($section) as $file) {
                if (isset($wanted[$file['path']]) || ($file['old_path'] !== null && isset($wanted[$file['old_path']]))) {
                    return true;
                }
            }
            return false;
        });

        return implode('', $kept);
    }

    public function schema(JsonSchema $schema): array
    {
        return PullRequestArguments::schema($schema) + [
            'paths' => $schema->array()->items($schema->string())->description('Optional: only these file paths (as in the changed files list).'),
        ];
    }
}
