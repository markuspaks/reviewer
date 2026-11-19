<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Open pull request, as last synced from Bitbucket.
 *
 * @property int $id
 * @property string $repository workspace/repository
 * @property int $pull_request_id
 * @property string|null $jira_issue_key
 * @property array $data Bitbucket pull request object, including participants
 * @property array|null $comments newest first; only stored when someone requested changes
 * @property array|null $last_pipeline latest pipeline of the source branch
 * @property array|null $diffstat {files, lines_added, lines_removed, source, destination}: diff size and the commits it is for
 * @property \Illuminate\Support\Carbon $pull_request_updated_on
 */
class PullRequestSnapshot extends Model
{
    protected $fillable = [
        'repository',
        'pull_request_id',
        'jira_issue_key',
        'data',
        'comments',
        'last_pipeline',
        'diffstat',
        'pull_request_updated_on',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'comments' => 'array',
            'last_pipeline' => 'array',
            'diffstat' => 'array',
            'pull_request_updated_on' => 'datetime',
        ];
    }
}
