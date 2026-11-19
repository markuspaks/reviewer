<?php

namespace App\Http\Controllers;

use App\Models\AgentFile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Files for AI agents: global ones and ones per repository. Agents read them through the MCP server.
 */
class AgentFileController extends Controller
{
    public function index(): View
    {
        $files = AgentFile::orderBy('name')->get();

        return view('files.index', [
            'globalFiles' => $files->whereNull('repository')->values(),
            'repositoryFiles' => $files->whereNotNull('repository')->sortBy('repository', SORT_NATURAL | SORT_FLAG_CASE)->groupBy('repository'),
            'repositories' => AgentFile::knownRepositories(),
            'maxLength' => AgentFile::MAX_LENGTH,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $file = AgentFile::create($data);

        return redirect()->to(url('files') . "#file-{$file->id}")->with('status', "Added {$file->name}.");
    }

    public function update(Request $request, AgentFile $file): RedirectResponse
    {
        $file->update($this->validated($request, $file));

        return redirect()->to(url('files') . "#file-{$file->id}")->with('status', "Saved {$file->name}.");
    }

    public function destroy(AgentFile $file): RedirectResponse
    {
        $file->delete();

        return redirect()->to(url('files'))->with('status', "Deleted {$file->name}.");
    }

    protected function validated(Request $request, ?AgentFile $file = null): array
    {
        $request->merge(['repository' => $request->input('repository') ?: null, 'name' => trim((string) $request->input('name'))]);

        return $request->validate(AgentFile::rules($request->input('repository'), $file), [
            'name.unique' => 'There is already a file with this name here.',
            'repository.regex' => 'Choose a repository from the list.',
        ]) + ['updated_by' => $request->user()->name];
    }
}
