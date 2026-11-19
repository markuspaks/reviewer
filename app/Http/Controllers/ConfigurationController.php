<?php

namespace App\Http\Controllers;

use App\Models\AgentFile;
use App\Models\PullRequest;
use App\Models\Setting;
use App\Services\Bitbucket;
use App\Models\User;
use App\Services\Jira;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConfigurationController extends Controller
{
    public function index(Bitbucket $bitbucket, Jira $jira): View
    {
        return view('configuration.index', [
            'users' => $bitbucket->getAllUsers(),
            'assigneeSource' => Setting::assigneeSource(),
            'jiraConfigured' => $jira->isConfigured(),
            'jiraDefaultUrl' => config('services.jira.url'),
            'jiraSites' => $jira->workspaceSites(),
            'workspaces' => array_values(array_unique(array_map(fn($repository) => explode('/', $repository, 2)[0], AgentFile::knownRepositories()))),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'assignee_source' => ['required', Rule::in(PullRequest::ASSIGNEE_SOURCES)],
            'jira_sites' => ['nullable', 'array'],
            'jira_sites.*' => ['nullable', 'url:https', 'max:255'],
        ], [
            'jira_sites.*.url' => 'Use the site address, e.g. https://example.atlassian.net.',
        ]);
        Setting::setValue(Setting::ASSIGNEE_SOURCE, $request->input('assignee_source'));

        // Only workspaces with their own site are stored; the rest use JIRA_URL.
        $sites = [];
        foreach ($request->input('jira_sites', []) as $workspace => $url) {
            $url = rtrim(trim((string) $url), '/');
            if ($url !== '' && $url !== rtrim((string) config('services.jira.url'), '/')) {
                $sites[$workspace] = $url;
            }
        }
        $sitesChanged = $sites !== app(Jira::class)->workspaceSites();
        Setting::setValue(Jira::SITES, json_encode($sites, JSON_UNESCAPED_SLASHES));

        $users = $request->input('users', []);
        foreach ($users as $userData) {
            if (!$userData['email']) {
                continue;
            }

            $uuid = str_replace(['{', '}'], '', $userData['uuid']);
            $user = User::find($uuid);
            if ($user === null) {
                $user = new User();
                $user->uuid = $uuid;
                $user->name = $userData['name'];
            }
            $user->email = $userData['email'];
            $user->save();
        }

        return redirect()->back()->with('status', $sitesChanged
            ? 'Saved. Tickets from the changed Jira sites are read on the next full sync, within '
                . config('reviewer.full_sync_minutes') . ' minutes.'
            : 'Saved.');
    }
}
