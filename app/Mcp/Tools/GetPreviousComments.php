<?php

namespace App\Mcp\Tools;

use App\Services\PreviousComments;
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
    Review comments from a repository's earlier pull requests (merged, declined and open), each with the code it was written on,
    to learn what the team's reviewers ask for. Pull requests are paged, most recently updated first: page 1 has the newest
    page_size pull requests; the answer says whether there is a next page. Pass "paths" (e.g. the files changed in the pull
    request you review) to get only comments on those files or folders. Bot comments are left out unless include_bots is true.
    TEXT)]
class GetPreviousComments extends Tool
{
    protected string $name = 'get_previous_comments';

    /** Most pull requests in one page. */
    protected const MAX_PAGE_SIZE = 100;

    /** Longest answer, in characters. */
    protected const MAX_LENGTH = 200000;

    public function __construct(protected PreviousComments $previousComments)
    {
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'repository' => PullRequestArguments::rules()['repository'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_PAGE_SIZE],
            'states' => ['nullable', 'array'],
            'states.*' => ['string', 'in:' . implode(',', array_map('strtolower', PreviousComments::STATES)) . ',' . implode(',', PreviousComments::STATES)],
            'paths' => ['nullable', 'array', 'max:500'],
            'paths.*' => ['string', 'max:1000'],
            'author' => ['nullable', 'string', 'max:100'],
            'include_bots' => ['nullable', 'boolean'],
        ], [
            'repository.regex' => 'Use workspace/repository, e.g. "acme/api".',
            'states.*.in' => 'States can be merged, declined or open.',
        ]);
        if (!config('bitbucket.connections.alternative.username') || !config('bitbucket.connections.alternative.password')) {
            return Response::error('Reviewer has no Bitbucket credentials for agents: set BITBUCKET_EMAIL and BITBUCKET_API_TOKEN in its .env.');
        }
        set_time_limit(600);

        $page = (int) ($data['page'] ?? 1);
        $pageSize = (int) ($data['page_size'] ?? 30);
        $states = array_values(array_unique(array_map('strtoupper', $data['states'] ?? PreviousComments::STATES)));
        try {
            $found = $this->previousComments->forRepository($data['repository'], $page, $pageSize, $states);
        } catch (RequestException|ConnectionException $e) {
            return Response::error('Bitbucket: ' . (($e instanceof RequestException ? $e->response->json('error.message') : null) ?? $e->getMessage()));
        }

        $paths = $data['paths'] ?? [];
        $author = isset($data['author']) ? mb_strtolower($data['author']) : null;
        $includeBots = (bool) ($data['include_bots'] ?? false);

        $sections = [];
        $threadCount = 0;
        foreach ($found['pull_requests'] as $pr) {
            $threads = [];
            foreach ($pr['threads'] as $thread) {
                if ($paths && !$this->matchesPath($thread['path'], $paths)) {
                    continue;
                }
                $comments = array_values(array_filter($thread['comments'], fn($c) => $includeBots || !$c['bot']));
                if (!$comments || ($author !== null && !array_filter($comments, fn($c) => str_contains(mb_strtolower($c['author']), $author)))) {
                    continue;
                }
                $threads[] = $this->thread($thread, $comments);
            }
            if ($threads) {
                $threadCount += count($threads);
                $sections[] = "## #{$pr['id']} {$pr['title']}\n\n" . strtolower($pr['state']) . ', by ' . ($pr['author']['display_name'] ?? '?')
                    . ', updated ' . Carbon::parse($pr['updated_on'])->toDateString() . ' · ' . ($pr['links']['html']['href'] ?? '')
                    . "\n\n" . implode("\n\n", $threads);
            }
        }

        $filters = array_filter([
            $paths ? 'on ' . implode(', ', $paths) : null,
            $author !== null ? "with comments by \"{$data['author']}\"" : null,
            $includeBots ? null : 'without bots',
        ]);
        $stateList = strtolower(implode(', ', $states));
        if ($found['from'] === 0) {
            return Response::text("# Previous review comments in {$data['repository']}\n\n"
                . "Page $page is past the end: there are {$found['total']} pull requests ($stateList).");
        }
        $pages = (int) ceil($found['total'] / $pageSize);
        $next = $found['has_more']
            ? 'Next: page ' . ($page + 1) . ' (same page_size and filters) for older pull requests.'
            : 'This is the last page.';

        $out = "# Previous review comments in {$data['repository']}\n\n"
            . "Page $page of $pages: pull requests {$found['from']}–{$found['to']} of {$found['total']} ($stateList), most recently updated first; "
            . "$threadCount comment threads" . ($filters ? ' ' . implode(', ', $filters) : '') . ". $next\n"
            . "Code is from each pull request's final diff: \">\" marks the commented line; \"outdated\" threads were on code changed later.\n\n"
            . ($sections ? implode("\n\n", $sections) : 'No comments on this page. ' . ($found['has_more'] ? 'Try the next page, or fewer filters.' : 'Try fewer filters.'));

        if (mb_strlen($out) > self::MAX_LENGTH) {
            $out = mb_substr($out, 0, self::MAX_LENGTH) . "\n\n(Cut off. Ask again with a smaller page_size, or pass paths.)";
        }

        return Response::text($out);
    }

    protected function matchesPath(?string $path, array $paths): bool
    {
        if ($path === null) {
            return false;
        }
        foreach ($paths as $wanted) {
            if ($path === $wanted || (str_ends_with($wanted, '/') && str_starts_with($path, $wanted))) {
                return true;
            }
        }

        return false;
    }

    protected function thread(array $thread, array $comments): string
    {
        $where = match (true) {
            $thread['path'] === null => 'On the pull request',
            $thread['line'] === null => "`{$thread['path']}` (whole file)",
            default => "`{$thread['path']}` line {$thread['line']} ({$thread['side']})",
        };
        $out = "### $where" . ($thread['outdated'] ? ' — outdated' : '') . "\n";
        if ($thread['code'] !== null) {
            $out .= "\n```\n{$thread['code']}\n```\n";
        } elseif ($thread['line'] !== null) {
            $out .= "\n(The commented code isn't in the final diff.)\n";
        }
        foreach ($comments as $i => $comment) {
            $out .= "\n" . ($i ? '  - reply by ' : '- ') . "{$comment['author']}, " . Carbon::parse($comment['created_on'])->toDateString() . ":\n"
                . preg_replace('/^/m', $i ? '    ' : '  ', rtrim($comment['content']));
        }

        return $out;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'repository' => $schema->string()->description('Repository as workspace/repository, e.g. "acme/api".')->required(),
            'page' => $schema->integer()->description('Page of pull requests, 1 = the most recently updated (default 1).'),
            'page_size' => $schema->integer()->description('Pull requests per page (default 30, at most 100).'),
            'states' => $schema->array()->items($schema->string()->enum(['merged', 'declined', 'open']))
                ->description('Pull request states to include (default all three).'),
            'paths' => $schema->array()->items($schema->string())
                ->description('Only comments on these files; a path ending in "/" matches a whole folder.'),
            'author' => $schema->string()->description('Only threads with a comment by this person (part of the name).'),
            'include_bots' => $schema->boolean()->description('Include comments by bots such as CodeRabbit (default false).'),
        ];
    }
}
