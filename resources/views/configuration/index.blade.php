@extends('layouts.main')

@section('title', 'Configuration')

@section('content')

    @include('configuration.account')

    @include('configuration.mcp')

    <form action="{{ url('configuration') }}" method="post">
        @csrf
        @method('PUT')

        <div class="field">
            <label class="label">Assignee source</label>
            <div class="control">
                <label class="radio">
                    <input type="radio" name="assignee_source" value="{{ \App\Models\PullRequest::ASSIGNEE_SOURCE_JIRA }}"
                           @checked($assigneeSource === \App\Models\PullRequest::ASSIGNEE_SOURCE_JIRA)>
                    Jira
                </label>
                <label class="radio">
                    <input type="radio" name="assignee_source" value="{{ \App\Models\PullRequest::ASSIGNEE_SOURCE_BITBUCKET }}"
                           @checked($assigneeSource === \App\Models\PullRequest::ASSIGNEE_SOURCE_BITBUCKET)>
                    Bitbucket
                </label>
            </div>
            <p class="help">
                Jira: the assignee of the issue in the pull request title or branch (e.g. ABC-123).
                Bitbucket: reviewers who haven't approved yet.
                Pull requests without a Jira issue or assignee always use Bitbucket.
            </p>
            @if($assigneeSource === \App\Models\PullRequest::ASSIGNEE_SOURCE_JIRA && !$jiraConfigured)
                <p class="help is-danger">
                    Jira isn't set up: add JIRA_URL, JIRA_EMAIL and JIRA_API_TOKEN to .env. Until then, Bitbucket is used.
                </p>
            @endif
            @error('assignee_source')
                <p class="help is-danger">{{ $message }}</p>
            @enderror
        </div>

        <div class="field jira-sites">
            <label class="label">Jira site per workspace</label>
            <p class="help">
                Where each Bitbucket workspace's tickets are, for assignees, ticket links and agents.
                Leave empty to use the default ({{ $jiraDefaultUrl ?: 'JIRA_URL is not set' }}).
                The Jira login (JIRA_EMAIL, JIRA_API_TOKEN) must be able to open each site.
            </p>
            @foreach($workspaces as $workspace)
                <div class="field is-horizontal">
                    <div class="field-label is-small"><label class="label" for="jira-site-{{ $workspace }}">{{ $workspace }}</label></div>
                    <div class="field-body">
                        <input class="input is-small" type="url" id="jira-site-{{ $workspace }}" name="jira_sites[{{ $workspace }}]"
                               value="{{ old("jira_sites.$workspace", $jiraSites[$workspace] ?? '') }}"
                               placeholder="{{ $jiraDefaultUrl }}">
                    </div>
                </div>
                @error("jira_sites.$workspace") <p class="help is-danger">{{ $message }}</p> @enderror
            @endforeach
        </div>

        <hr>

        <div>
            @foreach($users as $user)
                <div class="columns">
                    <div class="column is-1">
                        <img class="avatar" src="{{ $user['links']['avatar']['href'] }}"
                             alt="{{ $user['nickname'] }}"
                             title="{{ $user['nickname'] }}">
                    </div>
                    <div class="column">{{ $user['nickname'] }}</div>
                    <div class="column">
                        <input type="hidden" name="users[{{ $user['uuid'] }}][uuid]" value="{{ $user['uuid'] }}">
                        <input type="hidden" name="users[{{ $user['uuid'] }}][name]" value="{{ $user['nickname'] }}">
                        <input class="input" type="text" name="users[{{ $user['uuid'] }}][email]" value="{{ $user['email'] }}">
                    </div>
                </div>
            @endforeach

            <div class="columns">
                <div class="column has-text-right">
                    <button class="button is-success">Save</button>
                </div>
            </div>
        </div>
    </form>

@endsection
