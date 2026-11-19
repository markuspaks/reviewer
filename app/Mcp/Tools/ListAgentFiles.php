<?php

namespace App\Mcp\Tools;

use App\Models\AgentFile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Number;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description(<<<'TEXT'
    The team's files for agents. With a repository: the global files and that repository's files, with their content.
    Without: a list of all files (name, scope, size, who saved it last). Read a file before changing it with save_agent_file.
    TEXT)]
class ListAgentFiles extends Tool
{
    protected string $name = 'list_agent_files';

    public function handle(Request $request): Response
    {
        $data = $request->validate(['repository' => AgentFile::rules(null)['repository']], [
            'repository.regex' => 'Use workspace/repository, e.g. "acme/api", or omit it to list all files.',
        ]);
        $repository = $data['repository'] ?? null;

        if ($repository === null) {
            $files = AgentFile::orderByRaw('repository is not null')->orderBy('repository')->orderBy('name')->get();
            if ($files->isEmpty()) {
                return Response::text('There are no files for agents yet.');
            }
            return Response::text("# Files for agents (" . $files->count() . ")\n\n" . $files->map(fn(AgentFile $f) => "- `{$f->name}` — "
                . ($f->isGlobal() ? 'global' : "repository {$f->repository}") . ', ' . Number::fileSize(strlen($f->content))
                . ', updated ' . $f->updated_at->toDateString() . ($f->updated_by ? " by {$f->updated_by}" : ''))->implode("\n")
                . "\n\nPass a repository to read the files for it.");
        }

        $files = AgentFile::where(fn($q) => $q->whereNull('repository')->orWhere('repository', $repository))
            ->orderByRaw('repository is not null')->orderBy('name')->get();
        if ($files->isEmpty()) {
            return Response::text("No global files and none for $repository.");
        }

        return Response::text("# Files for agents: global and $repository\n" . $files->map(fn(AgentFile $f) => "\n## `{$f->name}` ("
            . ($f->isGlobal() ? 'global' : "repository {$f->repository}") . ($f->updated_by ? ", last saved by {$f->updated_by}" : '') . ")\n\n"
            . rtrim($f->content))->implode("\n"));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'repository' => $schema->string()->description('Repository as workspace/repository. Omit to list all files.'),
        ];
    }
}
