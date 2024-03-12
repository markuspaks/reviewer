<?php

namespace App\Models;

use App\Services\Bitbucket;
use Http\Client\Exception;

class PullRequest
{
    public const STATE_APPROVED = 'approved';
    public const STATE_CHANGES_REQUESTED = 'changes_requested';

    public const PIPELINE_STATE_SUCCESSFUL = 'successful';
    public const PIPELINE_STATE_FAILED = 'failed';
    public const PIPELINE_STATE_RUNNING = 'running';

    protected Bitbucket $bitbucket;

    protected array $data;

    protected ?array $comments = null;

    public function __construct(Bitbucket $bitbucket, $data)
    {
        $this->bitbucket = $bitbucket;
        $this->data = $data;
    }

    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @throws Exception
     */
    public function getComments(): array
    {
        if ($this->comments !== null) {
            return $this->comments;
        }

        return $this->comments = $this->bitbucket->getComments($this);
    }

    public function getLastPipeline(): ?array
    {
        return $this->bitbucket->getLastPipeline($this);
    }

    public function getLastPipelineState(): ?string
    {
        $pipeline = $this->getLastPipeline();

        if (!$pipeline) {
            return null;
        }

        $pipelineState = $pipeline['state']['result']['name'] ?? null;

        if ($pipelineState === null) {
            return self::PIPELINE_STATE_RUNNING;
        }

        return $pipelineState === 'SUCCESSFUL' ? self::PIPELINE_STATE_SUCCESSFUL : self::PIPELINE_STATE_FAILED;
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

    /**
     * @throws Exception
     */
    public function getAssignees(): array
    {
        $users = [];

        if ($this->isLastPipelineSuccessful()) {
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
                        if ($this->isNeedsReviewComment($comment['content']['raw']) && $comment['updated_on'] > $participant['participated_on']) {
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
        foreach ($texts as $text)
        {
            if (str_contains($comment, $text)) {
                return true;
            }
        }

        return false;
    }
}
