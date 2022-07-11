<?php

namespace App\Models;

use App\Http\Services\Bitbucket;
use Http\Client\Exception;

class PullRequest
{
    public const STATE_APPROVED = 'approved';
    public const STATE_CHANGES_REQUESTED = 'changes_requested';

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

    /**
     * @throws Exception
     */
    public function getAssignees(): array
    {
        $users = [];
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
                    if ($comment['content']['raw'] === 'review again' && $comment['updated_on'] > $participant['participated_on']) {
                        $excludeParticipant = false;
                    }
                }

                if ($excludeParticipant) {
                    continue;
                }
            }

            $users[] = $participant['user'];
        }

        if (!count($users)) {
            $users[] = $this->data['author'];
        }

        return $users;
    }
}
