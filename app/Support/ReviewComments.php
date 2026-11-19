<?php

namespace App\Support;

/**
 * Sorts pull request comments into threads and places them on the diff.
 *
 * A thread is ['root' => comment, 'replies' => [comment, ...]] (nested replies are flattened in time order).
 */
class ReviewComments
{
    /** @var array<int, array{root: array, replies: array}> threads on the whole pull request */
    public array $general = [];

    /** @var array<string, array<string, array>> path => "new:12" / "old:5" => threads */
    public array $inline = [];

    /**
     * Threads shown above a file's diff, each with a label: comments on the whole file,
     * on lines outside the shown diff, or outdated (the line changed since).
     *
     * @var array<string, array<int, array{label: string, thread: array}>>
     */
    public array $fileThreads = [];

    /** The user's comments saved here and not yet sent to Bitbucket. */
    public int $unsentCount = 0;

    /** The user's pending comments in Bitbucket's own draft review (started in Bitbucket); the API returns only theirs. */
    public int $bitbucketDraftCount = 0;

    /**
     * @param array $comments as returned by the API plus unsent drafts (DraftComment::toCommentArray), any order
     * @param array $files parsed diff (Diff::parse)
     */
    public function __construct(array $comments, array $files)
    {
        $byId = [];
        foreach ($comments as $comment) {
            $byId[$comment['id']] = $comment;
        }

        // Root of each comment (follow parent links).
        $rootOf = function (array $comment) use ($byId): int|string {
            $seen = [];
            while (isset($comment['parent']['id'], $byId[$comment['parent']['id']]) && !isset($seen[$comment['id']])) {
                $seen[$comment['id']] = true;
                $comment = $byId[$comment['parent']['id']];
            }
            return $comment['id'];
        };

        $threads = [];
        foreach ($byId as $id => $comment) {
            $rootId = $rootOf($comment);
            if ($rootId === $id) {
                $threads[$id]['root'] = $comment;
                $threads[$id]['replies'] ??= [];
            } else {
                $threads[$rootId]['replies'][] = $comment;
            }
        }

        $lineKeys = [];
        $filePaths = [];
        foreach ($files as $file) {
            $filePaths[$file['path']] = true;
            foreach ($file['hunks'] as $hunk) {
                foreach ($hunk['lines'] as $line) {
                    if ($line['new'] !== null) {
                        $lineKeys[$file['path']]["new:{$line['new']}"] = true;
                    }
                    if ($line['old'] !== null) {
                        $lineKeys[$file['path']]["old:{$line['old']}"] = true;
                    }
                }
            }
        }

        uasort($threads, fn($a, $b) => strcmp($a['root']['created_on'] ?? '', $b['root']['created_on'] ?? ''));
        foreach ($threads as $thread) {
            if (!isset($thread['root'])) {
                continue;
            }
            usort($thread['replies'], fn($a, $b) => strcmp($a['created_on'], $b['created_on']));
            $thread['replies'] = array_values(array_filter($thread['replies'], fn($c) => !($c['deleted'] ?? false)));
            if (($thread['root']['deleted'] ?? false) && !$thread['replies']) {
                continue;
            }

            foreach ([$thread['root'], ...$thread['replies']] as $comment) {
                if ($comment['local'] ?? false) {
                    $this->unsentCount++;
                } elseif (($comment['pending'] ?? false) && !($comment['deleted'] ?? false)) {
                    $this->bitbucketDraftCount++;
                }
            }

            $inline = $thread['root']['inline'] ?? null;
            if (!$inline) {
                $this->general[] = $thread;
                continue;
            }

            $path = $inline['path'];
            $line = $inline['to'] ?? $inline['from'] ?? null;
            $key = isset($inline['to']) ? "new:{$inline['to']}" : (isset($inline['from']) ? "old:{$inline['from']}" : null);
            $outdated = $inline['outdated'] ?? false;

            if (!isset($filePaths[$path])) {
                // The file is no longer part of the diff.
                $thread['label'] = $line ? "On $path, line $line" : "On $path";
                $this->general[] = $thread;
            } elseif ($key !== null && !$outdated && isset($lineKeys[$path][$key])) {
                $this->inline[$path][$key][] = $thread;
            } else {
                $label = match (true) {
                    $line === null => 'On the file',
                    $outdated => "Outdated: was on line $line",
                    default => "Line $line (outside the shown diff)",
                };
                $this->fileThreads[$path][] = ['label' => $label, 'thread' => $thread];
            }
        }
    }

    /**
     * Threads to show under a diff line. Context lines match comments on either side.
     */
    public function forLine(string $path, array $line): array
    {
        $threads = [];
        if ($line['new'] !== null) {
            array_push($threads, ...($this->inline[$path]["new:{$line['new']}"] ?? []));
        }
        if ($line['old'] !== null) {
            array_push($threads, ...($this->inline[$path]["old:{$line['old']}"] ?? []));
        }

        return $threads;
    }

    public function countForFile(string $path): int
    {
        $count = count($this->fileThreads[$path] ?? []);
        foreach ($this->inline[$path] ?? [] as $threads) {
            $count += count($threads);
        }

        return $count;
    }
}
