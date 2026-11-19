<?php

namespace App\Mcp;

use App\Models\AgentFile;
use App\Models\DraftComment;
use App\Models\PullRequestSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Markdown blocks the MCP tools return: pull requests (from the local copy made by the sync) and agent files.
 */
class ReviewContext
{
    public static function findPullRequest(string $repository, int $id): ?PullRequestSnapshot
    {
        return PullRequestSnapshot::where('repository', $repository)->where('pull_request_id', $id)->first();
    }

    public static function pullRequest(PullRequestSnapshot $snapshot, string $userUuid): string
    {
        $pr = $snapshot->data;
        $participants = collect($pr['participants'] ?? [])
            ->map(fn(array $p) => ($p['user']['display_name'] ?? '?')
                . ' (' . strtolower($p['role'] ?? 'participant') . ($p['state'] ? ', ' . str_replace('_', ' ', $p['state']) : '') . ')')
            ->implode(', ');
        $pipeline = $snapshot->last_pipeline;
        $pipelineState = $pipeline
            ? strtolower($pipeline['state']['result']['name'] ?? $pipeline['state']['stage']['name'] ?? $pipeline['state']['name'] ?? 'unknown')
            : 'none';
        [$workspace, $repository] = explode('/', $snapshot->repository, 2);
        $unsent = DraftComment::for($userUuid, $workspace, $repository, $snapshot->pull_request_id)->count();

        $lines = [
            "### {$snapshot->repository} #{$snapshot->pull_request_id}: {$pr['title']}",
            '',
            "- repository: `{$snapshot->repository}`, pull_request_id: `{$snapshot->pull_request_id}`",
            '- state: ' . strtolower($pr['state'] ?? 'open') . (($pr['draft'] ?? false) ? ' (draft)' : ''),
            "- author: " . ($pr['author']['display_name'] ?? '?'),
            "- branch: `{$pr['source']['branch']['name']}` → `{$pr['destination']['branch']['name']}`"
                . (isset($pr['source']['commit']['hash']) ? " (commit {$pr['source']['commit']['hash']})" : ''),
            '- reviewers and participants: ' . ($participants ?: 'none'),
            "- last pipeline: $pipelineState",
            '- comments in Bitbucket: ' . ($pr['comment_count'] ?? 0) . ', open tasks: ' . ($pr['task_count'] ?? 0)
                . ", your unsent comments: $unsent",
            '- updated: ' . Carbon::parse($pr['updated_on'])->toDateTimeString() . ' UTC',
            '- Bitbucket: ' . ($pr['links']['html']['href'] ?? ''),
            '- review page: ' . route('pull-requests.review', [$workspace, $repository, $snapshot->pull_request_id]),
            '',
            '#### Description',
            '',
            trim($pr['description'] ?? '') ?: '(none)',
        ];

        return implode("\n", $lines);
    }

    /**
     * Global files, then the files of each of the given repositories.
     *
     * @param string[] $repositories
     */
    public static function files(array $repositories): string
    {
        $repositories = array_values(array_unique($repositories));
        /** @var Collection<int, AgentFile> $files */
        $files = AgentFile::query()
            ->where(fn($query) => $query->whereNull('repository')->orWhereIn('repository', $repositories))
            ->orderByRaw('repository is not null')
            ->orderBy('repository')
            ->orderBy('name')
            ->get();

        if ($files->isEmpty()) {
            return "## Files for agents\n\nNo global files, and none for " . ($repositories ? implode(', ', $repositories) : 'these repositories') . '.';
        }

        $out = "## Files for agents\n\nRules and notes from the team. Follow them when reviewing, but don't name these files in comments. "
            . "Global files apply to every repository; repository files only to that repository.\n";
        foreach ($files as $file) {
            $scope = $file->isGlobal() ? 'global' : "repository {$file->repository}";
            $out .= "\n### {$file->name} ($scope)\n\n" . rtrim($file->content) . "\n";
        }
        $missing = array_diff($repositories, $files->pluck('repository')->filter()->all());
        if ($missing) {
            $out .= "\n(No repository files for " . implode(', ', $missing) . '.)';
        }

        return $out;
    }
}
