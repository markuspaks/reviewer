<?php

namespace App\Mcp\Tools;

use App\Models\AgentFile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description(<<<'TEXT'
    Add or change a file for agents: global (no repository) or for one repository. Agents get these files with every
    ticket and pull request, so keep them short, specific rules (e.g. conventions learned from previous review comments).
    mode "create" (default) adds a new file and fails if the name is taken; "append" adds to the end of an existing file;
    "replace" overwrites it, so read it first with list_agent_files. Pass your model name and effort; they are shown as
    who saved the file.
    TEXT)]
class SaveAgentFile extends Tool
{
    protected string $name = 'save_agent_file';

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'repository' => AgentFile::rules(null)['repository'],
            'name' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:' . AgentFile::MAX_LENGTH],
            'mode' => ['nullable', 'in:create,append,replace'],
            'model' => ['required', 'string', 'max:100'],
            'effort' => ['required', 'string', 'max:50'],
        ], [
            'repository.regex' => 'Use workspace/repository, e.g. "acme/api", or omit it for a global file.',
            'model.required' => 'Pass your model name, e.g. "Claude Opus 5.5". It is shown as who saved the file.',
            'effort.required' => 'Pass your reasoning effort, e.g. "high" ("unknown" if you don\'t know).',
        ]);
        $repository = $data['repository'] ?? null;
        $name = trim($data['name']);
        $mode = $data['mode'] ?? 'create';
        $updatedBy = trim($data['model']) . ' (' . trim(preg_replace('/\s*effort$/i', '', trim($data['effort']))) . ' effort)';
        $scope = $repository === null ? 'global' : "repository $repository";

        $file = AgentFile::where('name', $name)
            ->when($repository === null, fn($q) => $q->whereNull('repository'), fn($q) => $q->where('repository', $repository))
            ->first();

        if ($mode === 'create' && $file !== null) {
            return Response::error("A $scope file named \"$name\" exists already. Read it with list_agent_files, then use mode \"append\" or \"replace\".");
        }
        if ($mode !== 'create' && $file === null) {
            return Response::error("There is no $scope file named \"$name\" to $mode. Use mode \"create\".");
        }

        $content = $mode === 'append' ? rtrim($file->content) . "\n\n" . ltrim($data['content']) : $data['content'];
        $validator = Validator::make(compact('repository', 'name', 'content'), AgentFile::rules($repository, $file));
        if ($validator->fails()) {
            return Response::error(implode(' ', $validator->errors()->all()));
        }

        $file ??= new AgentFile(['repository' => $repository, 'name' => $name]);
        $file->fill(['content' => $content, 'updated_by' => $updatedBy])->save();

        $verb = ['create' => 'Added', 'append' => 'Appended to', 'replace' => 'Replaced'][$mode];

        return Response::text("$verb the $scope file \"$name\" (" . mb_strlen($content) . ' characters). '
            . 'People can review it on ' . url('files') . "#file-{$file->id}");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'repository' => $schema->string()->description('Repository as workspace/repository. Omit for a global file.'),
            'name' => $schema->string()->description('File name, e.g. "review-rules.md".')->required(),
            'content' => $schema->string()->description('The file content (Markdown), or the text to add with mode "append".')->required(),
            'mode' => $schema->string()->enum(['create', 'append', 'replace'])->description('"create" (default), "append" or "replace".'),
            'model' => $schema->string()->description('Your model name, e.g. "Claude Opus 5.5".')->required(),
            'effort' => $schema->string()->description('Your reasoning effort: low, medium, high, xhigh, max, or "unknown".')->required(),
        ];
    }
}
