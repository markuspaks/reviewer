<?php

namespace App\Services;

use App\Support\Diff;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Review comments from a repository's earlier pull requests, each with the code it was written on,
 * so agents can learn what the team asks for in reviews. Read from Bitbucket with the app's credentials.
 *
 * Each pull request's comments are cached by its updated_on, so only new activity is fetched again.
 */
class PreviousComments
{
    /** Pull requests fetched at the same time. */
    protected const CONCURRENCY = 8;

    /** Bitbucket's largest page of pull requests. */
    protected const BITBUCKET_PAGE_SIZE = 50;

    /** Commented files whose code is fetched per pull request (each is a URL parameter). */
    protected const MAX_DIFF_PATHS = 100;

    /** Diffs larger than this (bytes) are not read for code. */
    protected const MAX_DIFF_BYTES = 5_000_000;

    /** Lines of code shown before and after the commented line. */
    protected const CONTEXT_LINES = 3;

    public const STATES = ['MERGED', 'DECLINED', 'OPEN'];

    /**
     * One page of the repository's pull requests, most recently updated first.
     *
     * @param int $page 1-based
     * @param string[] $states pull request states to look at
     * @return array{total: int, from: int, to: int, has_more: bool, pull_requests: array<int, array>}
     *         from/to: positions of the pull requests looked at (1-based); pull_requests: those with comments, each with 'threads'
     */
    public function forRepository(string $repository, int $page, int $pageSize, array $states = self::STATES): array
    {
        [$workspace, $name] = explode('/', $repository, 2);
        $base = 'repositories/' . rawurlencode($workspace) . '/' . rawurlencode($name) . '/pullrequests';

        // Bitbucket pages hold 50; start at the one containing this page's first pull request.
        $offset = ($page - 1) * $pageSize;
        $bitbucketPage = intdiv($offset, self::BITBUCKET_PAGE_SIZE) + 1;
        $skip = $offset % self::BITBUCKET_PAGE_SIZE;
        $query = 'state=' . implode('&state=', $states) . '&sort=-updated_on&pagelen=' . self::BITBUCKET_PAGE_SIZE . "&page=$bitbucketPage"
            . '&fields=next,size,values.id,values.title,values.state,values.updated_on,values.comment_count,'
            . 'values.author.display_name,values.links.html.href';

        $pullRequests = [];
        $total = 0;
        $url = "$base?$query";
        while ($url !== null && count($pullRequests) < $skip + $pageSize) {
            $response = $this->http()->get($url)->throw()->json();
            $total = $response['size'] ?? $total;
            array_push($pullRequests, ...($response['values'] ?? []));
            $url = $response['next'] ?? null;
        }
        $pullRequests = array_slice($pullRequests, $skip, $pageSize);

        $withComments = array_values(array_filter($pullRequests, fn(array $pr) => ($pr['comment_count'] ?? 0) > 0));
        $threads = [];
        $missing = [];
        foreach ($withComments as $pr) {
            $cached = Cache::get($this->cacheKey($repository, $pr));
            if ($cached !== null) {
                $threads[$pr['id']] = $cached;
            } else {
                $missing[] = $pr;
            }
        }

        foreach (array_chunk($missing, self::CONCURRENCY) as $chunk) {
            $comments = $this->pool(array_map(fn(array $pr) => "$base/{$pr['id']}/comments?pagelen=100&sort=created_on", $chunk));
            $needDiff = [];
            $failed = [];
            foreach ($chunk as $i => $pr) {
                $failed[$i] = !($comments[$i] instanceof Response && $comments[$i]->successful());
                $values = $this->allPages($comments[$i]);
                $comments[$i] = $values;
                // Only the commented files' part of the diff: whole diffs can be huge.
                $paths = array_slice(array_values(array_unique(array_filter(array_map(
                    fn(array $comment) => ($comment['deleted'] ?? false) ? null : ($comment['inline']['path'] ?? null),
                    $values
                )))), 0, self::MAX_DIFF_PATHS);
                if ($paths) {
                    $needDiff[$i] = "$base/{$pr['id']}/diff?" . implode('&', array_map(fn($path) => 'path=' . rawurlencode($path), $paths));
                }
            }
            $diffs = $needDiff ? $this->pool($needDiff, text: true) : [];

            foreach ($chunk as $i => $pr) {
                $diff = isset($diffs[$i]) && $diffs[$i] instanceof Response && $diffs[$i]->successful()
                    && strlen($diffs[$i]->body()) <= self::MAX_DIFF_BYTES ? $diffs[$i]->body() : null;
                unset($diffs[$i]);
                $threads[$pr['id']] = $this->threads($comments[$i] ?? [], $diff);
                if (!$failed[$i]) {
                    Cache::put($this->cacheKey($repository, $pr), $threads[$pr['id']], now()->addDays(30));
                }
            }
        }

        $result = [];
        foreach ($withComments as $pr) {
            if (!empty($threads[$pr['id']])) {
                $result[] = $pr + ['threads' => $threads[$pr['id']]];
            }
        }

        return [
            'total' => $total,
            'from' => $pullRequests ? $offset + 1 : 0,
            'to' => $offset + count($pullRequests),
            'has_more' => $offset + count($pullRequests) < $total,
            'pull_requests' => $result,
        ];
    }

    /**
     * Comments grouped into threads (root first, replies in time order), deleted and pending ones left out,
     * with the commented code for inline threads.
     */
    protected function threads(array $comments, ?string $diff): array
    {
        $byId = [];
        foreach ($comments as $comment) {
            if (!($comment['deleted'] ?? false) && !($comment['pending'] ?? false)) {
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
        $grouped = [];
        foreach ($byId as $comment) {
            $grouped[$rootOf($comment)][] = $comment;
        }

        $files = [];
        foreach ($diff !== null ? Diff::parse($diff) : [] as $file) {
            $files[$file['path']] = $file;
        }

        $threads = [];
        foreach ($grouped as $rootId => $thread) {
            usort($thread, fn($a, $b) => strcmp($a['created_on'], $b['created_on']));
            $root = $byId[$rootId];
            $inline = $root['inline'] ?? null;
            $threads[] = [
                'path' => $inline['path'] ?? null,
                'line' => $inline['to'] ?? $inline['from'] ?? null,
                'side' => isset($inline['to']) ? 'new' : (isset($inline['from']) ? 'old' : null),
                'outdated' => (bool) ($inline['outdated'] ?? false),
                'code' => $inline ? $this->code($files[$inline['path']] ?? null, $inline) : null,
                'comments' => array_map(fn(array $c) => [
                    'id' => $c['id'],
                    'author' => $c['user']['display_name'] ?? '?',
                    'bot' => $this->isBot($c),
                    'created_on' => $c['created_on'],
                    'content' => $c['content']['raw'] ?? '',
                ], $thread),
            ];
        }

        return $threads;
    }

    /**
     * The diff lines around the commented line, the commented one marked with ">". Null when it isn't in the diff
     * (the comment is on an older version of the code, or on the whole file).
     */
    protected function code(?array $file, array $inline): ?string
    {
        $side = isset($inline['to']) ? 'new' : (isset($inline['from']) ? 'old' : null);
        if ($file === null || $side === null) {
            return null;
        }
        $number = $side === 'new' ? $inline['to'] : $inline['from'];

        foreach ($file['hunks'] as $hunk) {
            $lines = array_values(array_filter($hunk['lines'], fn($l) => $l['type'] !== 'meta'));
            foreach ($lines as $index => $line) {
                $matches = $side === 'new'
                    ? $line['new'] === $number && $line['type'] !== 'del'
                    : $line['old'] === $number && $line['type'] !== 'add';
                if (!$matches) {
                    continue;
                }
                $out = [];
                foreach (array_slice($lines, max(0, $index - self::CONTEXT_LINES), self::CONTEXT_LINES * 2 + 1, true) as $i => $l) {
                    $sign = ['add' => '+', 'del' => '-'][$l['type']] ?? ' ';
                    $out[] = ($i === $index ? '>' : ' ') . str_pad((string) ($l['new'] ?? $l['old']), 5, ' ', STR_PAD_LEFT) . " $sign {$l['text']}";
                }
                return implode("\n", $out);
            }
        }

        return null;
    }

    /**
     * Comments by apps, and by review bots such as CodeRabbit that post through a person's account.
     */
    protected function isBot(array $comment): bool
    {
        $raw = $comment['content']['raw'] ?? '';

        return ($comment['user']['type'] ?? null) === 'app_user'
            || preg_match('/\bbot\b/i', $comment['user']['display_name'] ?? '')
            || str_contains($raw, 'coderabbit.ai/')
            || str_contains($raw, '<!-- This is an auto-generated comment');
    }

    /**
     * Run GET requests in parallel; returns the responses (or exceptions) in the same order.
     */
    protected function pool(array $urls, bool $text = false): array
    {
        $keys = array_keys($urls);
        $responses = Http::pool(fn(Pool $pool) => array_map(
            fn(string $url) => $this->authenticate($pool->baseUrl('https://api.bitbucket.org/2.0/')->timeout(120))
                ->withHeaders(['Accept' => $text ? 'text/plain' : 'application/json'])
                ->get($url),
            array_values($urls)
        ));

        return array_combine($keys, $responses);
    }

    /**
     * All comments from a first-page response, following "next" links.
     */
    protected function allPages(mixed $response): array
    {
        if (!$response instanceof Response || !$response->successful()) {
            return [];
        }
        $values = $response->json('values') ?? [];
        $next = $response->json('next');
        while ($next) {
            $page = $this->http()->get($next)->throw()->json();
            array_push($values, ...($page['values'] ?? []));
            $next = $page['next'] ?? null;
        }

        return $values;
    }

    protected function cacheKey(string $repository, array $pr): string
    {
        return "previous-comments:v4:$repository#{$pr['id']}:{$pr['updated_on']}";
    }

    protected function http(): PendingRequest
    {
        return $this->authenticate(Http::baseUrl('https://api.bitbucket.org/2.0/')->acceptJson()->timeout(60));
    }

    protected function authenticate(PendingRequest $request): PendingRequest
    {
        $connection = config('bitbucket.connections.alternative');

        return $request->withBasicAuth((string) $connection['username'], (string) $connection['password']);
    }
}
