@php use App\Models\PullRequest;use Carbon\Carbon; @endphp
@extends('layouts.main')

@section('content')

    <form class="review-filters" method="get" action="{{ route('reviews.index') }}">
        @if($reviewer)
            <input type="hidden" name="reviewer" value="{{ $reviewer }}">
        @endif
        <div class="select is-small">
            <select name="workspace" aria-label="Workspace" class="js-filter-workspace">
                <option value="">All workspaces</option>
                @foreach($workspaces as $name => $count)
                    <option value="{{ $name }}" @selected(($filters['workspace'] ?? null) === $name)>{{ $name }} ({{ $count }})</option>
                @endforeach
            </select>
        </div>
        <div class="select is-small">
            <select name="repository" aria-label="Repository" class="js-filter-repository">
                <option value="">All repositories</option>
                @foreach($repositories as $name => $count)
                    <option value="{{ $name }}" @selected(($filters['repository'] ?? null) === $name)>{{ $name }} ({{ $count }})</option>
                @endforeach
            </select>
        </div>
        <div class="select is-small">
            <select name="author" aria-label="Author">
                <option value="">All authors</option>
                @foreach($authors as $uuid => $author)
                    <option value="{{ $uuid }}" @selected(($filters['author'] ?? null) === $uuid)>{{ $author['user']['display_name'] ?? $author['user']['nickname'] }} ({{ $author['count'] }})</option>
                @endforeach
            </select>
        </div>
        <div class="select is-small">
            <select name="sort" aria-label="Sort">
                @foreach($sorts as $value => $label)
                    <option value="{{ $value }}" @selected($sort === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <noscript><button class="button is-small" type="submit">Filter</button></noscript>
        @if(array_diff_key($filters, ['sort' => true]) || $reviewer)
            <a class="button is-small is-text" href="{{ route('reviews.index', array_intersect_key($filters, ['sort' => true])) }}">Show all</a>
        @endif
    </form>

    <div class="counters">
        @foreach($users as $user)
            @php
                $pullRequestCount = count($user['pull_requests']);
                $countTitle = "{$user['nickname']}: {$user['work_items']} " . ($user['work_items'] === 1 ? 'work item' : 'work items')
                    . ", $pullRequestCount " . ($pullRequestCount === 1 ? 'pull request' : 'pull requests');
            @endphp
            <a class="counter @if($reviewer === $user['uuid']) is-selected @endif"
               href="{{ route('reviews.index', $filters + ['reviewer' => $user['uuid']]) }}"
               title="{{ $countTitle }}">
                <img class="avatar" src="{{ $user['links']['avatar']['href'] }}"
                     alt="{{ $user['nickname'] }}"> {{ $user['work_items'] }}
            </a>
        @endforeach
    </div>

    <div>
        <table class="reviews">
            @foreach($groups as $group)
                <tr>
                    <td class="nowrap">
                        @foreach($group->getAuthors() as $author)
                            <img class="avatar" src="{{ $author['links']['avatar']['href'] }}"
                                 alt="{{ $author['nickname'] }}"
                                 title="{{ $author['nickname'] }}">
                        @endforeach
                    </td>
                    <td class="nowrap">
                        @if($issueKey = $group->getIssueKey())
                            {{ $issueKey }}
                            <button type="button" class="copy-prompt js-copy"
                                    data-copy="Review ticket {{ $issueKey }} with the reviewer MCP: start with get_ticket, read each pull request's diff and comments, then add your findings as review comments."
                                    title="Copy a prompt for an AI agent to review {{ $issueKey }} with the reviewer MCP"
                                    aria-label="Copy AI review prompt for {{ $issueKey }}">
                                <svg width="14" height="14" viewBox="0 0 16 16" aria-hidden="true">
                                    <rect x="5" y="5" width="9" height="9" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.5"/>
                                    <path d="M11 5V3.5A1.5 1.5 0 0 0 9.5 2h-6A1.5 1.5 0 0 0 2 3.5v6A1.5 1.5 0 0 0 3.5 11H5" fill="none" stroke="currentColor" stroke-width="1.5"/>
                                </svg>
                            </button>
                        @else
                            —
                        @endif
                    </td>
                    <td>
                        @if($group->getUrl())
                            <a href="{{ $group->getUrl() }}" target="_blank" rel="noopener noreferrer">{{ $group->getTitle() }}</a>
                        @else
                            {{ $group->getTitle() }}
                        @endif
                    </td>
                    <td class="nowrap">
                        {{ (new Carbon($group->getUpdatedOn()))->diffForHumans() }}
                    </td>
                    <td class="nowrap">
                        @include('partials.diff-size', ['size' => $group->getDiffSize()])
                    </td>
                    <td class="nowrap">
                        @foreach($group->getAssignees() as $assignee)
                            <img class="avatar" src="{{ $assignee['links']['avatar']['href'] }}"
                                 alt="{{ $assignee['nickname'] }}"
                                 title="{{ $assignee['nickname'] }}">
                        @endforeach
                    </td>
                    <td>
                        <details class="pr-pane">
                            <summary class="button is-small is-rounded"
                                     title="{{ $group->count() }} pull {{ $group->count() === 1 ? 'request' : 'requests' }}">{{ $group->count() }}</summary>
                            <div class="pr-pane-content box">
                                @foreach($group->getPullRequests() as $pullRequest)
                                    @php $pullRequestData = $pullRequest->getData(); @endphp
                                    <div class="pr-pane-item">
                                        <span class="pipeline-icon">
                                            @if($pullRequest->getLastPipelineState())
                                                @include('partials.pipeline-icon', ['state' => $pullRequest->getLastPipelineState()])
                                            @endif
                                        </span>
                                        <div class="pr-pane-text">
                                            @php [$prWorkspace, $prRepository] = explode('/', $pullRequestData['destination']['repository']['full_name'] ?? $pullRequestData['source']['repository']['full_name']); @endphp
                                            <a href="{{ route('pull-requests.review', [$prWorkspace, $prRepository, $pullRequestData['id']]) }}" target="_blank" rel="noopener noreferrer">{{ $pullRequestData['title'] }}</a>
                                            <div class="pr-pane-meta">
                                                {{ $pullRequestData['source']['repository']['full_name'] }}
                                                · {{ (new Carbon($pullRequestData['updated_on']))->diffForHumans() }}
                                                @if($pullRequest->getDiffSize())
                                                    · @include('partials.diff-size', ['size' => $pullRequest->getDiffSize()])
                                                @endif
                                                · <a href="{{ $pullRequestData['links']['html']['href'] }}" target="_blank" rel="noopener noreferrer">Bitbucket</a>
                                            </div>
                                        </div>
                                        <div class="nowrap">
                                            @foreach($pullRequestData['participants'] as $participant)
                                                <img
                                                    class="avatar @if($participant['state'] === 'approved')approved @elseif($participant['state'] === 'changes_requested')changes @endif"
                                                    src="{{ $participant['user']['links']['avatar']['href'] }}"
                                                    alt="{{ $participant['user']['nickname'] }}"
                                                    title="{{ $participant['user']['nickname'] }}">
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    </td>
                </tr>
            @endforeach
        </table>
        Total {{ count($groups) }} {{ count($groups) === 1 ? 'ticket' : 'tickets' }},
        {{ count($pullRequests) }} pull {{ count($pullRequests) === 1 ? 'request' : 'requests' }}.
    </div>

@endsection
