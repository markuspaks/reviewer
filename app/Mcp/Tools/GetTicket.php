<?php

namespace App\Mcp\Tools;

use App\Mcp\ReviewContext;
use App\Models\JiraIssue;
use App\Models\PullRequestSnapshot;
use App\Services\Jira;
use App\Support\AdfToMarkdown;
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
    Everything needed to review a Jira ticket: the ticket (summary, status, people, description, comments), all its open
    pull requests in Bitbucket (repository, id, branches, reviewers, pipeline, description), and the team's files for agents
    (global rules plus the rules of each repository with a pull request). Start a review here, then read each pull request's
    diff with get_pull_request_diff and its comments with get_pull_request_comments.
    TEXT)]
class GetTicket extends Tool
{
    protected string $name = 'get_ticket';

    public function __construct(protected Jira $jira)
    {
    }

    public function handle(Request $request): Response
    {
        $request->validate(['ticket' => ['required', 'string', 'max:500']]);
        $key = Jira::findIssueKey(strtoupper(trim($request->get('ticket'))));
        if ($key === null) {
            return Response::error('No ticket number found in "' . $request->get('ticket') . '". Use a Jira key such as ABC-123.');
        }

        $snapshots = PullRequestSnapshot::where('jira_issue_key', $key)->orderBy('repository')->orderBy('pull_request_id')->get();
        $userUuid = $request->user()->uuid;

        // Read the ticket from the Jira site of its pull requests' workspace.
        $workspaces = $snapshots->map(fn($s) => explode('/', $s->repository, 2)[0])->unique()->values()->all();
        $out = $this->ticket($key, $workspaces) . "\n\n## Open pull requests (" . $snapshots->count() . ")\n\n";
        $out .= $snapshots->isEmpty()
            ? "No open pull requests mention $key in their title or branch."
            : $snapshots->map(fn($s) => ReviewContext::pullRequest($s, $userUuid))->implode("\n\n");
        $out .= "\n\n" . ReviewContext::files($snapshots->pluck('repository')->all());

        return Response::text($out);
    }

    /**
     * @param string[] $workspaces workspaces of the ticket's pull requests; their Jira sites are tried first, then the others
     */
    protected function ticket(string $key, array $workspaces): string
    {
        $sites = array_values(array_unique([...array_map(fn($w) => $this->jira->siteUrl($w), $workspaces), ...$this->jira->siteUrls()]));

        if (!$this->jira->isConfigured()) {
            return "# Ticket $key\n\n" . $this->localSummary($key) . "\n\n(Jira isn't set up in Reviewer, so only the synced summary is shown.)";
        }

        $issue = null;
        $site = $sites[0] ?? null;
        $errors = [];
        foreach ($sites as $candidate) {
            try {
                $issue = $this->jira->fetchIssueDetails($key, $candidate);
            } catch (RequestException|ConnectionException $e) {
                $errors[] = "$candidate: " . ($e instanceof RequestException ? 'HTTP ' . $e->response->status() : $e->getMessage());
                continue;
            }
            if ($issue !== null) {
                $site = $candidate;
                break;
            }
        }
        $heading = "# Ticket $key" . ($site ? " ($site/browse/" . rawurlencode($key) . ')' : '');

        if ($issue === null) {
            return "$heading\n\n" . $this->localSummary($key) . "\n\n"
                . ($errors
                    ? "(Couldn't read the ticket from Jira: " . implode('; ', $errors) . ')'
                    : "(No Jira site has ticket $key, or the Reviewer Jira account can't see it. Tried: " . implode(', ', $sites)
                        . '. A workspace\'s Jira site is set on the Reviewer Configuration page.)');
        }

        $f = $issue['fields'] ?? [];
        $name = fn(?array $person) => $person['displayName'] ?? 'nobody';
        $lines = [
            $heading,
            '',
            '**' . ($f['summary'] ?? '') . '**',
            '',
            '- type: ' . ($f['issuetype']['name'] ?? '?') . ', status: ' . ($f['status']['name'] ?? '?') . ', priority: ' . ($f['priority']['name'] ?? '?'),
            '- assignee: ' . $name($f['assignee'] ?? null) . ', reporter: ' . $name($f['reporter'] ?? null),
        ];
        if (!empty($f['labels'])) {
            $lines[] = '- labels: ' . implode(', ', $f['labels']);
        }
        if (!empty($f['components'])) {
            $lines[] = '- components: ' . implode(', ', array_column($f['components'], 'name'));
        }
        if (!empty($f['fixVersions'])) {
            $lines[] = '- fix versions: ' . implode(', ', array_column($f['fixVersions'], 'name'));
        }
        if (isset($f['parent'])) {
            $lines[] = "- parent: {$f['parent']['key']} " . ($f['parent']['fields']['summary'] ?? '');
        }
        foreach ($f['subtasks'] ?? [] as $subtask) {
            $lines[] = "- subtask: {$subtask['key']} " . ($subtask['fields']['summary'] ?? '') . ' [' . ($subtask['fields']['status']['name'] ?? '') . ']';
        }
        foreach ($f['issuelinks'] ?? [] as $link) {
            $other = $link['outwardIssue'] ?? $link['inwardIssue'] ?? null;
            $verb = isset($link['outwardIssue']) ? ($link['type']['outward'] ?? 'relates to') : ($link['type']['inward'] ?? 'relates to');
            if ($other) {
                $lines[] = "- $verb {$other['key']} " . ($other['fields']['summary'] ?? '');
            }
        }
        $lines[] = '- created ' . Carbon::parse($f['created'] ?? 'now')->toDateString() . ', updated ' . Carbon::parse($f['updated'] ?? 'now')->toDateString();
        $lines[] = '';
        $lines[] = '## Description';
        $lines[] = '';
        $lines[] = AdfToMarkdown::convert($f['description'] ?? null) ?: '(none)';

        $comments = $f['comment']['comments'] ?? [];
        if ($comments) {
            $lines[] = '';
            $lines[] = '## Ticket comments (' . count($comments) . ')';
            foreach ($comments as $comment) {
                $lines[] = '';
                $lines[] = '**' . $name($comment['author'] ?? null) . '**, ' . Carbon::parse($comment['created'])->toDateTimeString() . ':';
                $lines[] = AdfToMarkdown::convert($comment['body'] ?? null);
            }
        }

        return implode("\n", $lines);
    }

    protected function localSummary(string $key): string
    {
        $issue = JiraIssue::find($key);

        return $issue
            ? "**{$issue->summary}**\n\n- assignee: " . ($issue->assignee['displayName'] ?? 'nobody')
            : 'Not synced yet.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'ticket' => $schema->string()->description('Jira ticket number, such as ABC-123 (a Jira link works too).')->required(),
        ];
    }
}
