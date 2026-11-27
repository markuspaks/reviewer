<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddReviewComment;
use App\Mcp\Tools\DeleteReviewComments;
use App\Mcp\Tools\EditReviewComment;
use App\Mcp\Tools\GetPreviousComments;
use App\Mcp\Tools\GetPullRequest;
use App\Mcp\Tools\GetPullRequestComments;
use App\Mcp\Tools\GetPullRequestDiff;
use App\Mcp\Tools\GetTicket;
use App\Mcp\Tools\ListAgentFiles;
use App\Mcp\Tools\ResolveCommentThread;
use App\Mcp\Tools\SaveAgentFile;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Reviewer')]
#[Version('1.0.0')]
#[Instructions(<<<'TEXT'
    Reviewer helps review the team's Bitbucket pull requests for a Jira ticket.
    1. Call get_ticket with the ticket number: the ticket, its open pull requests, and the team's files for agents.
       Follow the rules in those files; repository files apply only to their repository.
    2. For each pull request, read the changes with get_pull_request_diff and the discussion with get_pull_request_comments.
    3. Add findings with add_review_comment, on the exact file and line where possible, one issue per comment.
       Explain each issue on its own merits; don't refer to the files for agents or their names (e.g. "per review-rules.md").
       Don't call something missing because a local checkout lacks it: that checkout may be outdated or on another branch.
       Look for it in the ticket's pull requests in the other repositories, and say which one is missing it (or that none
       of the ticket's pull requests adds it); never mention the local checkout in a comment.
       Always pass your model name and reasoning effort. Comments are saved unsent; the human reviewer checks and sends them.
       To fix or withdraw your own unsent comments (listed as drafts by get_pull_request_comments), use edit_review_comment
       or delete_review_comments; the reviewer's own comments can't be changed.
    4. Resolve a thread (or reopen one) with resolve_comment_thread and the id of its first comment. Unlike comments
       this acts in Bitbucket at once, as the reviewer: do it when the reviewer asks you to, or only as the team's
       files for agents allow.
    To learn what the team's reviewers ask for, read get_previous_comments for a repository (pass the changed files as paths).
    When asked, turn recurring feedback into rules with save_agent_file (global, or for one repository); read the existing
    files with list_agent_files first and don't duplicate them.
    TEXT)]
class ReviewerServer extends Server
{
    protected array $tools = [
        GetTicket::class,
        GetPullRequest::class,
        GetPullRequestDiff::class,
        GetPullRequestComments::class,
        GetPreviousComments::class,
        AddReviewComment::class,
        EditReviewComment::class,
        DeleteReviewComments::class,
        ResolveCommentThread::class,
        ListAgentFiles::class,
        SaveAgentFile::class,
    ];
}
