<?php

namespace App\Models;

use App\Services\Bitbucket;
use App\Services\Jira;

class PullRequest
{
    public const STATE_APPROVED = 'approved';
    public const STATE_CHANGES_REQUESTED = 'changes_requested';

    public const PIPELINE_STATE_SUCCESSFUL = 'successful';
    public const PIPELINE_STATE_FAILED = 'failed';
    public const PIPELINE_STATE_RUNNING = 'running';

    protected Bitbucket $bitbucket;

    protected array $data;

    protected array $comments;

    protected ?array $lastPipeline;

    protected ?array $assignees = null;

    protected ?array $diffstat;

    /**
     * @param array $data Bitbucket pull request object, including participants
     * @param array $comments newest first (only synced when someone requested changes)
     * @param array|null $lastPipeline latest pipeline of the source branch
     * @param array|null $diffstat diff size: files, lines_added, lines_removed (null until synced)
     */
    public function __construct(Bitbucket $bitbucket, array $data, array $comments = [], ?array $lastPipeline = null, ?array $diffstat = null)
    {
        $this->bitbucket = $bitbucket;
        $this->data = $data;
        $this->comments = $comments;
        $this->lastPipeline = $lastPipeline;
        $this->diffstat = $diffstat;
    }

    public function getAuthor(): array
    {
        return $this->data['author'];
    }

    /**
     * Diff size, or null when it hasn't been synced yet.
     *
     * @return array{files: int, lines_added: int, lines_removed: int}|null
     */
    public function getDiffSize(): ?array
    {
        if (!isset($this->diffstat['files'])) {
            return null;
        }

        return [
            'files' => (int) $this->diffstat['files'],
            'lines_added' => (int) $this->diffstat['lines_added'],
            'lines_removed' => (int) $this->diffstat['lines_removed'],
        ];
    }

    public function getData(): array
    {
        return $this->data;
    }

    /**
     * The repository the pull request merges into, as workspace/repository.
     */
    public function getRepository(): string
    {
        return $this->data['destination']['repository']['full_name'] ?? $this->data['source']['repository']['full_name'];
    }

    public function getWorkspace(): string
    {
        return explode('/', $this->getRepository(), 2)[0];
    }

    public function getComments(): array
    {
        return $this->comments;
    }

    public function getLastPipeline(): ?array
    {
        return $this->lastPipeline;
    }

    public function getLastPipelineState(): ?string
    {
        $pipeline = $this->getLastPipeline();

        if (!$pipeline) {
            return null;
        }

        $pipelineResult = $pipeline['state']['result']['name'] ?? null;
        $pipelineStage = $pipeline['state']['stage']['name'] ?? null;

        if ($pipelineResult === 'SUCCESSFUL' || $pipelineStage === 'PAUSED') {
            return self::PIPELINE_STATE_SUCCESSFUL;
        }

        if ($pipelineResult === 'FAILED') {
            return self::PIPELINE_STATE_FAILED;
        }

        return self::PIPELINE_STATE_RUNNING;
    }

    public function isLastPipelineSuccessful(): ?bool
    {
        $pipeline = $this->getLastPipeline();

        if (!$pipeline) {
            return null;
        }

        $pipelineState = $pipeline['state']['result']['name'] ?? null;
        return $pipelineState === 'SUCCESSFUL';
    }

    public const ASSIGNEE_SOURCE_BITBUCKET = 'bitbucket';
    public const ASSIGNEE_SOURCE_JIRA = 'jira';
    public const ASSIGNEE_SOURCES = [self::ASSIGNEE_SOURCE_JIRA, self::ASSIGNEE_SOURCE_BITBUCKET];

    /**
     * Users the pull request is waiting on, from the source chosen on the Configuration page.
     *
     */
    public function getAssignees(): array
    {
        if ($this->assignees !== null) {
            return $this->assignees;
        }

        if (Setting::assigneeSource() === self::ASSIGNEE_SOURCE_JIRA) {
            $assignees = $this->getJiraAssignees();
            if ($assignees !== null) {
                return $this->assignees = $assignees;
            }
        }

        return $this->assignees = $this->getBitbucketAssignees();
    }

    /**
     * Jira issue key from the pull request title, or else from its source branch.
     */
    public function getJiraIssueKey(): ?string
    {
        return Jira::findIssueKey(
            $this->data['title'] ?? null,
            $this->data['source']['branch']['name'] ?? null,
        );
    }

    /**
     * The assignee of the linked Jira issue, or null when there is none to use.
     */
    public function getJiraAssignees(): ?array
    {
        $issueKey = $this->getJiraIssueKey();
        if ($issueKey === null) {
            return null;
        }

        $jiraAssignee = app(Jira::class)->getIssueAssignee($issueKey);
        if (!isset($jiraAssignee['accountId'])) {
            return null;
        }

        $user = $this->findParticipantByAccountId($jiraAssignee['accountId'])
            ?? $this->bitbucket->findUserByAccountId($jiraAssignee['accountId']);

        // Not a Bitbucket workspace member: show the Jira user, it just won't be counted per reviewer.
        $user ??= [
            'uuid' => null,
            'account_id' => $jiraAssignee['accountId'],
            'nickname' => $jiraAssignee['displayName'] ?? $jiraAssignee['accountId'],
            'display_name' => $jiraAssignee['displayName'] ?? null,
            'links' => ['avatar' => ['href' => $jiraAssignee['avatarUrls']['48x48'] ?? null]],
        ];

        return [$user];
    }

    protected function findParticipantByAccountId(string $accountId): ?array
    {
        $users = array_merge(
            [$this->data['author']],
            array_column($this->data['participants'] ?? [], 'user'),
            $this->data['reviewers'] ?? [],
        );

        foreach ($users as $user) {
            if (($user['account_id'] ?? null) === $accountId) {
                return $user;
            }
        }

        return null;
    }

    /**
     * Reviewers who haven't approved yet; the author when the pipeline failed or nobody is left.
     *
     */
    public function getBitbucketAssignees(): array
    {
        $users = [];

        $isLastPipelineSuccessful = $this->isLastPipelineSuccessful();
        if ($isLastPipelineSuccessful === null || $isLastPipelineSuccessful) {
            foreach ($this->data['participants'] as $participant) {
                if ($participant['user']['account_id'] === $this->data['author']['account_id']) {
                    continue;
                }

                if ($participant['state'] === self::STATE_APPROVED) {
                    continue;
                }

                if ($participant['state'] === self::STATE_CHANGES_REQUESTED) {
                    $excludeParticipant = true;
                    foreach ($this->getComments() as $comment) {
                        // If comment review again is newer than participation older
                        if ($this->isNeedsReviewComment(
                                $comment['content']['raw']
                            ) && $comment['updated_on'] > $participant['participated_on']) {
                            $excludeParticipant = false;
                            break;
                        }
                    }

                    if ($excludeParticipant) {
                        continue;
                    }
                }

                $users[] = $participant['user'];
            }
        }

        if (!count($users)) {
            $users[] = $this->data['author'];
        }

        return $users;
    }

    public function isNeedsReviewComment(string $comment): bool
    {
        $comment = mb_strtolower($comment);
        $texts = ['review again', 'needs review'];
        foreach ($texts as $text) {
            if (str_contains($comment, $text)) {
                return true;
            }
        }

        return false;
    }
}
