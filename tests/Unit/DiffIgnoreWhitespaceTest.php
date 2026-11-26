<?php

namespace Tests\Unit;

use App\Support\Diff;
use PHPUnit\Framework\TestCase;

class DiffIgnoreWhitespaceTest extends TestCase
{
    public function test_a_reindented_block_counts_as_unchanged_and_the_version_stays(): void
    {
        $diff = <<<'DIFF'
            diff --git a/app/Foo.php b/app/Foo.php
            index 1111111..2222222 100644
            --- a/app/Foo.php
            +++ b/app/Foo.php
            @@ -1,5 +1,5 @@
             <?php
            -if ($a) {
            -return 1;
            -}
            +if ($a) {
            +    return 1;
            +}
             // end
            DIFF;

        $parsed = Diff::parse($diff);
        $files = Diff::ignoreWhitespace($parsed);

        $this->assertCount(1, $files);
        $this->assertSame(0, $files[0]['additions']);
        $this->assertSame(0, $files[0]['deletions']);
        $this->assertSame([], $files[0]['hunks']);
        $this->assertTrue($files[0]['whitespace_only']);
        $this->assertSame($parsed[0]['version'], $files[0]['version']);
        $this->assertFalse($parsed[0]['whitespace_only']);
    }

    public function test_only_the_real_change_in_a_block_stays_with_its_line_numbers(): void
    {
        $diff = <<<'DIFF'
            diff --git a/a.txt b/a.txt
            --- a/a.txt
            +++ b/a.txt
            @@ -10,5 +10,5 @@ context
             keep
            -  alpha
            -beta
            -gamma = 1
            +alpha
            +beta changed
            +gamma=1
             keep too
            DIFF;

        $files = Diff::ignoreWhitespace(Diff::parse($diff));
        $file = $files[0];

        $this->assertSame(1, $file['additions']);
        $this->assertSame(1, $file['deletions']);
        $this->assertFalse($file['whitespace_only']);
        $this->assertCount(1, $file['hunks']);
        $this->assertSame([
            ['type' => 'context', 'old' => 10, 'new' => 10, 'text' => 'keep'],
            // Shown as it is in the new version, numbered on both sides.
            ['type' => 'context', 'old' => 11, 'new' => 11, 'text' => 'alpha'],
            ['type' => 'del', 'old' => 12, 'new' => null, 'text' => 'beta'],
            ['type' => 'add', 'old' => null, 'new' => 12, 'text' => 'beta changed'],
            ['type' => 'context', 'old' => 13, 'new' => 13, 'text' => 'gamma=1'],
            ['type' => 'context', 'old' => 14, 'new' => 14, 'text' => 'keep too'],
        ], $file['hunks'][0]['lines']);
    }

    public function test_lines_are_matched_out_of_step_when_lines_were_added_or_removed(): void
    {
        $diff = <<<'DIFF'
            diff --git a/a.txt b/a.txt
            --- a/a.txt
            +++ b/a.txt
            @@ -1,3 +1,3 @@
            -one
            -two
            -three
            +  one
            +inserted
            +  two
            DIFF;

        $lines = Diff::ignoreWhitespace(Diff::parse($diff))[0]['hunks'][0]['lines'];

        $this->assertSame([
            ['type' => 'context', 'old' => 1, 'new' => 1, 'text' => '  one'],
            ['type' => 'add', 'old' => null, 'new' => 2, 'text' => 'inserted'],
            ['type' => 'context', 'old' => 2, 'new' => 3, 'text' => '  two'],
            ['type' => 'del', 'old' => 3, 'new' => null, 'text' => 'three'],
        ], $lines);
    }

    public function test_hunks_without_other_changes_are_dropped_and_the_rest_kept(): void
    {
        $diff = <<<'DIFF'
            diff --git a/a.txt b/a.txt
            --- a/a.txt
            +++ b/a.txt
            @@ -1,2 +1,2 @@
            -x = 1
            +x=1
             y
            @@ -20,2 +20,3 @@
             a
            +b
             c
            DIFF;

        $file = Diff::ignoreWhitespace(Diff::parse($diff))[0];

        $this->assertCount(1, $file['hunks']);
        $this->assertSame(20, $file['hunks'][0]['new_start']);
        $this->assertSame(1, $file['additions']);
        $this->assertSame(0, $file['deletions']);
        $this->assertFalse($file['whitespace_only']);
    }

    public function test_added_and_deleted_files_and_binary_files_are_left_alone(): void
    {
        $diff = <<<'DIFF'
            diff --git a/new.txt b/new.txt
            new file mode 100644
            --- /dev/null
            +++ b/new.txt
            @@ -0,0 +1,2 @@
            +hello
            +world
            diff --git a/gone.txt b/gone.txt
            deleted file mode 100644
            --- a/gone.txt
            +++ /dev/null
            @@ -1 +0,0 @@
            -bye
            diff --git a/img.png b/img.png
            Binary files a/img.png and b/img.png differ
            DIFF;

        $files = Diff::ignoreWhitespace(Diff::parse($diff));

        $this->assertSame([2, 0, 0], array_column($files, 'additions'));
        $this->assertSame([0, 1, 0], array_column($files, 'deletions'));
        $this->assertSame([false, false, false], array_column($files, 'whitespace_only'));
        $this->assertCount(1, $files[0]['hunks']);
        $this->assertCount(1, $files[1]['hunks']);
    }

    public function test_the_no_newline_note_stays_with_its_line(): void
    {
        $diff = <<<'DIFF'
            diff --git a/a.txt b/a.txt
            --- a/a.txt
            +++ b/a.txt
            @@ -1,2 +1,2 @@
             first
            -last
            \ No newline at end of file
            +  last
            DIFF;

        $lines = Diff::ignoreWhitespace(Diff::parse($diff))[0]['hunks'][0]['lines'] ?? [];

        // Nothing but whitespace changed, so the hunk goes; the note went nowhere unexpected.
        $this->assertSame([], $lines);

        $diff = <<<'DIFF'
            diff --git a/a.txt b/a.txt
            --- a/a.txt
            +++ b/a.txt
            @@ -1,2 +1,2 @@
            -first
            -last
            \ No newline at end of file
            +  first
            +other
            DIFF;

        $lines = Diff::ignoreWhitespace(Diff::parse($diff))[0]['hunks'][0]['lines'];

        $this->assertSame([
            ['type' => 'context', 'old' => 1, 'new' => 1, 'text' => '  first'],
            ['type' => 'del', 'old' => 2, 'new' => null, 'text' => 'last'],
            ['type' => 'meta', 'old' => null, 'new' => null, 'text' => 'No newline at end of file'],
            ['type' => 'add', 'old' => null, 'new' => 2, 'text' => 'other'],
        ], $lines);
    }
}
