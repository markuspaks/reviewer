<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Jira issue referenced by an open pull request, as last synced from Jira.
 *
 * @property string $key
 * @property string|null $summary
 * @property array|null $assignee Jira user (accountId, displayName, avatarUrls, ...)
 */
class JiraIssue extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'summary', 'assignee'];

    protected function casts(): array
    {
        return ['assignee' => 'array'];
    }
}
