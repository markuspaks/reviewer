<?php

namespace Tests\Unit;

use App\Support\Diff;
use PHPUnit\Framework\TestCase;

class DiffTreeTest extends TestCase
{
    public function test_sort_by_tree_lists_files_as_the_tree_does(): void
    {
        $files = array_map(fn (string $path) => ['path' => $path], [
            'routes/web.php',
            'app/Support/diff.php',
            'README.md',
            'app/Support/Comments.php',
            'app/Http/Controllers/ReviewController.php',
            'composer.json',
        ]);

        $sorted = Diff::sortByTree($files);

        $this->assertSame([
            'app/Http/Controllers/ReviewController.php',
            'app/Support/Comments.php',
            'app/Support/diff.php',
            'routes/web.php',
            'composer.json',
            'README.md',
        ], array_column($sorted, 'path'));

        // Tree indexes point at the sorted files in order.
        $tree = Diff::tree($sorted);
        $this->assertSame(['app', 'routes'], array_keys($tree['folders']));
        $this->assertSame(['Http/Controllers', 'Support'], array_keys($tree['folders']['app']['folders']));
        $this->assertSame([1, 2], $tree['folders']['app']['folders']['Support']['files']);
        $this->assertSame([4, 5], $tree['files']);
    }
}
