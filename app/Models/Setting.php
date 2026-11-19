<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App-wide settings changed from the Configuration page (shared by all users and the send-emails command).
 *
 * @property string $key
 * @property string|null $value
 */
class Setting extends Model
{
    public const ASSIGNEE_SOURCE = 'assignee_source';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    /**
     * Values already read in this request.
     *
     * @var array<string, string|null>
     */
    protected static array $loaded = [];

    public static function getValue(string $key, ?string $default = null): ?string
    {
        if (!array_key_exists($key, static::$loaded)) {
            static::$loaded[$key] = static::find($key)?->value;
        }

        return static::$loaded[$key] ?? $default;
    }

    public static function setValue(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        static::$loaded[$key] = $value;
    }

    /**
     * Where pull request assignees come from: PullRequest::ASSIGNEE_SOURCE_JIRA or ..._BITBUCKET.
     */
    public static function assigneeSource(): string
    {
        $source = static::getValue(self::ASSIGNEE_SOURCE);

        return in_array($source, PullRequest::ASSIGNEE_SOURCES, true)
            ? $source
            : config('reviewer.assignee_source');
    }
}
