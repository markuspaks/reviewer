<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A review comment saved on the review page and not yet sent to Bitbucket. Only its author sees it.
 *
 * @property int $id
 * @property string $user_uuid without braces, like users.uuid
 * @property string|null $author_name AI agent that wrote it (model and effort); null when the user wrote it
 * @property string $repository workspace/repository
 * @property int $pull_request_id
 * @property int|null $parent_id Bitbucket comment this replies to
 * @property string|null $path
 * @property int|null $line
 * @property string|null $side old / new
 * @property string $content
 */
class DraftComment extends Model
{
    protected $fillable = ['user_uuid', 'author_name', 'repository', 'pull_request_id', 'parent_id', 'path', 'line', 'side', 'content'];

    protected $casts = [
        'pull_request_id' => 'integer',
        'parent_id' => 'integer',
        'line' => 'integer',
    ];

    public function scopeFor(Builder $query, string $userUuid, string $workspace, string $repository, int $pullRequestId): void
    {
        $query->where('user_uuid', trim($userUuid, '{}'))
            ->where('repository', "$workspace/$repository")
            ->where('pull_request_id', $pullRequestId);
    }

    /**
     * Bitbucket's "inline" object for this comment, or null for a comment on the pull request.
     */
    public function inline(): ?array
    {
        if ($this->path === null) {
            return null;
        }
        $inline = ['path' => $this->path];
        if ($this->line !== null) {
            $inline[$this->side === 'old' ? 'from' : 'to'] = $this->line;
        }

        return $inline;
    }

    /**
     * The draft shaped like a Bitbucket comment, so it is placed on the diff the same way.
     *
     * @param array $user Bitbucket user (display_name, links.avatar)
     */
    public function toCommentArray(array $user): array
    {
        return [
            'id' => "draft-{$this->id}",
            'draft_id' => $this->id,
            'local' => true,
            'content' => ['raw' => $this->content],
            // An agent's comment shows the agent as author; it is still the user's to check and send.
            'user' => $this->author_name !== null
                ? ['uuid' => $user['uuid'] ?? null, 'display_name' => $this->author_name, 'links' => ['avatar' => ['href' => '']]]
                : $user,
            'agent' => $this->author_name !== null,
            'created_on' => $this->created_at->toIso8601String(),
            'updated_on' => $this->updated_at->toIso8601String(),
            'deleted' => false,
            'pending' => false,
        ]
            + ($this->parent_id !== null ? ['parent' => ['id' => $this->parent_id]] : [])
            + ($this->inline() !== null ? ['inline' => $this->inline()] : []);
    }
}
