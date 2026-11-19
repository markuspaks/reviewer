<?php

namespace App\Models;

use App\Models\Setting;
use App\Services\Sync;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A file of rules or notes for AI agents (review guidelines, coding standards, ...), given to them through MCP.
 * Global files apply to every pull request, repository files only to pull requests in that repository.
 *
 * @property int $id
 * @property string|null $repository workspace/repository; null for a global file
 * @property string $name
 * @property string $content
 * @property string|null $updated_by a person's name, or an agent's model and effort
 */
class AgentFile extends Model
{
    protected $fillable = ['repository', 'name', 'content', 'updated_by'];

    public function scopeGlobal(Builder $query): void
    {
        $query->whereNull('repository');
    }

    /**
     * @param string[] $repositories workspace/repository names
     */
    public function scopeForRepositories(Builder $query, array $repositories): void
    {
        $query->whereIn('repository', $repositories);
    }

    /**
     * Rules for the file fields, shared by the Files page and the MCP tool.
     */
    public static function rules(?string $repository, ?self $ignore = null): array
    {
        return [
            'repository' => ['nullable', 'string', 'max:255', 'regex:#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#'],
            'name' => [
                'required', 'string', 'max:255',
                \Illuminate\Validation\Rule::unique('agent_files')->where(fn($query) => $repository === null
                    ? $query->whereNull('repository')
                    : $query->where('repository', $repository))->ignore($ignore),
            ],
            'content' => ['required', 'string', 'max:' . self::MAX_LENGTH],
        ];
    }

    /** Largest file, in characters. */
    public const MAX_LENGTH = 200000;

    public function isGlobal(): bool
    {
        return $this->repository === null;
    }

    /**
     * All repositories the sync knows (workspace/repository), sorted.
     *
     * @return string[]
     */
    public static function knownRepositories(): array
    {
        $repositories = array_keys(json_decode(Setting::getValue(Sync::REPOSITORY_UPDATED_ON) ?? '[]', true) ?: []);
        $repositories = array_unique([...$repositories, ...static::query()->whereNotNull('repository')->pluck('repository')->all()]);
        sort($repositories, SORT_NATURAL | SORT_FLAG_CASE);

        return $repositories;
    }
}
