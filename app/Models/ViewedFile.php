<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A file a user marked as viewed in a pull request. It counts as viewed only while its diff is unchanged ($version).
 *
 * @property string $user_uuid without braces
 * @property string $repository workspace/repository
 * @property int $pull_request_id
 * @property string $path
 * @property string $path_hash
 * @property string $version
 */
class ViewedFile extends Model
{
    protected $fillable = ['user_uuid', 'repository', 'pull_request_id', 'path', 'path_hash', 'version'];

    public function scopeFor(Builder $query, string $userUuid, string $workspace, string $repository, int $pullRequestId): void
    {
        $query->where('user_uuid', trim($userUuid, '{}'))
            ->where('repository', "$workspace/$repository")
            ->where('pull_request_id', $pullRequestId);
    }

    /**
     * Versions the user saw, per path.
     *
     * @return array<string, string>
     */
    public static function versions(string $userUuid, string $workspace, string $repository, int $pullRequestId): array
    {
        return static::for($userUuid, $workspace, $repository, $pullRequestId)->pluck('version', 'path')->all();
    }
}
