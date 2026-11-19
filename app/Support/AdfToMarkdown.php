<?php

namespace App\Support;

/**
 * Converts Jira rich text (Atlassian Document Format) to Markdown, for agents to read.
 * Unknown nodes keep their text content.
 */
class AdfToMarkdown
{
    public static function convert(?array $document): string
    {
        if (!$document) {
            return '';
        }

        return trim(preg_replace("/\n{3,}/", "\n\n", (new static())->blocks($document['content'] ?? [])));
    }

    protected function blocks(array $nodes, string $indent = ''): string
    {
        return implode('', array_map(fn(array $node) => $this->block($node, $indent), $nodes));
    }

    protected function block(array $node, string $indent): string
    {
        $content = $node['content'] ?? [];

        return match ($node['type'] ?? '') {
            'paragraph' => $indent . $this->inline($content) . "\n\n",
            'heading' => str_repeat('#', (int) ($node['attrs']['level'] ?? 1)) . ' ' . $this->inline($content) . "\n\n",
            'bulletList' => $this->listItems($content, $indent, false) . "\n",
            'orderedList' => $this->listItems($content, $indent, true, (int) ($node['attrs']['order'] ?? 1)) . "\n",
            'codeBlock' => "```" . ($node['attrs']['language'] ?? '') . "\n" . $this->plain($content) . "\n```\n\n",
            'blockquote' => preg_replace('/^/m', '> ', trim($this->blocks($content))) . "\n\n",
            'rule' => "---\n\n",
            'panel' => '> **' . ucfirst($node['attrs']['panelType'] ?? 'note') . ":**\n" . preg_replace('/^/m', '> ', trim($this->blocks($content))) . "\n\n",
            'table' => $this->table($content) . "\n",
            'mediaSingle', 'mediaGroup', 'media' => "[attachment]\n\n",
            'expand', 'nestedExpand' => '**' . ($node['attrs']['title'] ?? '') . "**\n\n" . $this->blocks($content),
            default => isset($node['text']) || in_array($node['type'] ?? '', ['mention', 'emoji', 'inlineCard', 'hardBreak', 'status', 'date'], true)
                ? $indent . $this->inline([$node]) . "\n\n"
                : $this->blocks($content, $indent),
        };
    }

    protected function listItems(array $items, string $indent, bool $ordered, int $start = 1): string
    {
        $out = '';
        foreach (array_values($items) as $i => $item) {
            $marker = $ordered ? ($start + $i) . '. ' : '- ';
            $first = true;
            foreach ($item['content'] ?? [] as $child) {
                if (in_array($child['type'] ?? '', ['bulletList', 'orderedList'], true)) {
                    $out .= rtrim($this->block($child, $indent . '  '), "\n") . "\n";
                } else {
                    $text = trim($this->block($child, ''));
                    $out .= $first ? $indent . $marker . $text . "\n" : $indent . '  ' . $text . "\n";
                    $first = false;
                }
            }
        }

        return $out;
    }

    protected function table(array $rows): string
    {
        $lines = [];
        foreach (array_values($rows) as $i => $row) {
            $cells = array_map(fn(array $cell) => str_replace(["\n", '|'], [' ', '\|'], trim($this->blocks($cell['content'] ?? []))), $row['content'] ?? []);
            $lines[] = '| ' . implode(' | ', $cells) . ' |';
            if ($i === 0) {
                $lines[] = '|' . str_repeat(' --- |', count($cells));
            }
        }

        return implode("\n", $lines) . "\n";
    }

    protected function inline(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $out .= match ($node['type'] ?? '') {
                'text' => $this->marks($node['text'] ?? '', $node['marks'] ?? []),
                'hardBreak' => "\n",
                'mention' => '@' . ltrim($node['attrs']['text'] ?? 'someone', '@'),
                'emoji' => $node['attrs']['text'] ?? $node['attrs']['shortName'] ?? '',
                'inlineCard', 'blockCard' => $node['attrs']['url'] ?? '',
                'status' => '[' . ($node['attrs']['text'] ?? '') . ']',
                'date' => isset($node['attrs']['timestamp']) ? date('Y-m-d', (int) ($node['attrs']['timestamp'] / 1000)) : '',
                default => $this->inline($node['content'] ?? []),
            };
        }

        return $out;
    }

    protected function marks(string $text, array $marks): string
    {
        foreach ($marks as $mark) {
            $text = match ($mark['type'] ?? '') {
                'code' => "`$text`",
                'strong' => "**$text**",
                'em' => "*$text*",
                'strike' => "~~$text~~",
                'link' => isset($mark['attrs']['href']) ? "[$text]({$mark['attrs']['href']})" : $text,
                default => $text,
            };
        }

        return $text;
    }

    protected function plain(array $nodes): string
    {
        return implode('', array_map(fn(array $node) => $node['text'] ?? $this->plain($node['content'] ?? []), $nodes));
    }
}
