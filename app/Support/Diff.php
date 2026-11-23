<?php

namespace App\Support;

/**
 * Parses a git unified diff (as returned by Bitbucket) into files, hunks and lines.
 */
class Diff
{
    public const ADDED = 'added';
    public const DELETED = 'deleted';
    public const RENAMED = 'renamed';
    public const MODIFIED = 'modified';

    /**
     * @return array<int, array{
     *     path: string, old_path: ?string, new_path: ?string, status: string, binary: bool,
     *     additions: int, deletions: int, version: string,
     *     hunks: array<int, array{header: string, old_start: int, old_count: int, new_start: int, new_count: int, lines: array<int, array{type: string, old: ?int, new: ?int, text: string}>}>
     * }>
     */
    public static function parse(string $diff): array
    {
        $files = [];
        $file = null;
        $hunk = null;
        // Lines still expected in the current hunk, from its "@@ -a,b +c,d @@" header.
        $oldLeft = $newLeft = 0;
        $old = $new = 0;

        $finishHunk = function () use (&$file, &$hunk) {
            if ($hunk !== null) {
                $file['hunks'][] = $hunk;
                $hunk = null;
            }
        };
        // The file's part of the diff, hashed into its version: a file marked as viewed counts as unviewed once it changes.
        $raw = '';
        $finishFile = function () use (&$files, &$file, &$raw, $finishHunk) {
            if ($file === null) {
                return;
            }
            $finishHunk();
            $file['version'] = substr(sha1($raw), 0, 16);
            $raw = '';
            $file['path'] = $file['new_path'] ?? $file['old_path'] ?? '(unknown)';
            if ($file['status'] === self::MODIFIED && $file['old_path'] && $file['new_path'] && $file['old_path'] !== $file['new_path']) {
                $file['status'] = self::RENAMED;
            }
            $files[] = $file;
            $file = null;
        };

        foreach (explode("\n", str_replace("\r\n", "\n", $diff)) as $line) {
            if (str_starts_with($line, 'diff --git ')) {
                $finishFile();
            }
            $raw .= $line . "\n";
            $inHunkBody = $hunk !== null && ($oldLeft > 0 || $newLeft > 0);

            if ($inHunkBody) {
                $prefix = $line[0] ?? ' ';
                $text = (string) substr($line, 1);
                if ($prefix === '+') {
                    $hunk['lines'][] = ['type' => 'add', 'old' => null, 'new' => $new++, 'text' => $text];
                    $file['additions']++;
                    $newLeft--;
                } elseif ($prefix === '-') {
                    $hunk['lines'][] = ['type' => 'del', 'old' => $old++, 'new' => null, 'text' => $text];
                    $file['deletions']++;
                    $oldLeft--;
                } elseif ($prefix === '\\') {
                    $hunk['lines'][] = ['type' => 'meta', 'old' => null, 'new' => null, 'text' => ltrim(substr($line, 1))];
                } else {
                    $hunk['lines'][] = ['type' => 'context', 'old' => $old++, 'new' => $new++, 'text' => $text];
                    $oldLeft--;
                    $newLeft--;
                }
                continue;
            }

            if (str_starts_with($line, '\\') && $hunk !== null) {
                // "\ No newline at end of file" right after the hunk's last line.
                $hunk['lines'][] = ['type' => 'meta', 'old' => null, 'new' => null, 'text' => ltrim(substr($line, 1))];
                continue;
            }

            if (str_starts_with($line, 'diff --git ')) {
                [$oldPath, $newPath] = self::pathsFromGitHeader(substr($line, 11));
                $file = [
                    'path' => '', 'old_path' => $oldPath, 'new_path' => $newPath, 'status' => self::MODIFIED,
                    'binary' => false, 'additions' => 0, 'deletions' => 0, 'hunks' => [],
                ];
                continue;
            }
            if ($file === null) {
                continue;
            }

            if (preg_match('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/', $line, $m)) {
                $finishHunk();
                $old = (int) $m[1];
                $new = (int) $m[3];
                $oldLeft = ($m[2] ?? '') === '' ? 1 : (int) $m[2];
                $newLeft = ($m[4] ?? '') === '' ? 1 : (int) $m[4];
                $hunk = [
                    'header' => $line,
                    'old_start' => $old, 'old_count' => $oldLeft, 'new_start' => $new, 'new_count' => $newLeft,
                    'lines' => [],
                ];
            } elseif (str_starts_with($line, 'new file mode')) {
                $file['status'] = self::ADDED;
            } elseif (str_starts_with($line, 'deleted file mode')) {
                $file['status'] = self::DELETED;
            } elseif (str_starts_with($line, 'rename from ')) {
                $file['old_path'] = substr($line, 12);
                $file['status'] = self::RENAMED;
            } elseif (str_starts_with($line, 'rename to ')) {
                $file['new_path'] = substr($line, 10);
                $file['status'] = self::RENAMED;
            } elseif (str_starts_with($line, 'Binary files ') || str_starts_with($line, 'GIT binary patch')) {
                $file['binary'] = true;
            } elseif (str_starts_with($line, '--- ')) {
                $file['old_path'] = self::pathFromMarker(substr($line, 4), 'a/');
            } elseif (str_starts_with($line, '+++ ')) {
                $file['new_path'] = self::pathFromMarker(substr($line, 4), 'b/');
            }
        }
        $finishFile();

        foreach ($files as &$parsed) {
            if ($parsed['status'] === self::ADDED) {
                $parsed['old_path'] = null;
            } elseif ($parsed['status'] === self::DELETED) {
                $parsed['new_path'] = null;
                $parsed['path'] = $parsed['old_path'] ?? $parsed['path'];
            }
        }

        return $files;
    }

    /**
     * Folders and files as a nested tree; single-child folder chains are joined ("app/Http/Controllers").
     *
     * @param array<int, array{path: string}> $files
     * @return array{folders: array<string, array>, files: array<int, int>} file entries are indexes into $files
     */
    public static function tree(array $files): array
    {
        $root = ['folders' => [], 'files' => []];
        foreach ($files as $index => $file) {
            $parts = explode('/', $file['path']);
            array_pop($parts);
            $node = &$root;
            foreach ($parts as $part) {
                $node['folders'][$part] ??= ['folders' => [], 'files' => []];
                $node = &$node['folders'][$part];
            }
            $node['files'][] = $index;
            unset($node);
        }

        return self::compact($root, $files);
    }

    protected static function compact(array $node, array $files): array
    {
        $folders = [];
        foreach ($node['folders'] as $name => $child) {
            $child = self::compact($child, $files);
            while (count($child['folders']) === 1 && !$child['files']) {
                $only = array_key_first($child['folders']);
                $name .= '/' . $only;
                $child = $child['folders'][$only];
            }
            $folders[$name] = $child;
        }
        ksort($folders, SORT_NATURAL | SORT_FLAG_CASE);
        $indexes = $node['files'];
        usort($indexes, fn (int $a, int $b) => strnatcasecmp(basename($files[$a]['path']), basename($files[$b]['path'])));

        return ['folders' => $folders, 'files' => $indexes];
    }

    /**
     * Files reordered as the tree lists them (folders first, both sorted by name), so the diff pane follows the tree.
     *
     * @param array<int, array{path: string}> $files
     * @return array<int, array{path: string}>
     */
    public static function sortByTree(array $files): array
    {
        $sorted = [];
        foreach (self::flatten(self::tree($files)) as $index) {
            $sorted[] = $files[$index];
        }

        return $sorted;
    }

    /** @return array<int, int> file indexes in tree order */
    protected static function flatten(array $node): array
    {
        $indexes = [];
        foreach ($node['folders'] as $folder) {
            array_push($indexes, ...self::flatten($folder));
        }

        return [...$indexes, ...$node['files']];
    }

    protected static function pathFromMarker(string $marker, string $prefix): ?string
    {
        $marker = rtrim(preg_replace('/\t.*$/', '', $marker));
        if ($marker === '/dev/null') {
            return null;
        }

        return str_starts_with($marker, $prefix) ? substr($marker, strlen($prefix)) : $marker;
    }

    /**
     * "a/x b/x" → [x, x]. Paths can contain spaces; when both halves are equal (no rename) split in the middle.
     *
     * @return array{0: ?string, 1: ?string}
     */
    protected static function pathsFromGitHeader(string $header): array
    {
        $length = strlen($header);
        if ($length % 2 === 1 && str_starts_with($header, 'a/')) {
            $half = ($length - 1) / 2;
            $left = substr($header, 2, $half - 2);
            $right = substr($header, $half + 3);
            if ($left === $right) {
                return [$left, $right];
            }
        }
        if (preg_match('#^a/(.+?) b/(.+)$#', $header, $m)) {
            return [$m[1], $m[2]];
        }

        return [null, null];
    }
}
