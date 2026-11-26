@extends('layouts.main')

@section('fluid', true)
@section('title', $pullRequest['title'])

@php
    use App\Support\Diff;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Str;

    $bitbucketUrl = $pullRequest['links']['html']['href'];
    $pipelineState = $lastPipeline['state']['result']['name'] ?? (($lastPipeline['state']['stage']['name'] ?? null) === 'PAUSED' ? 'PAUSED' : ($lastPipeline['state']['name'] ?? null));
@endphp

@section('content')
<div class="review"
     data-comments-url="{{ url("pull-requests/$workspace/$repository/{$pullRequest['id']}/comments") }}"
     data-drafts-url="{{ route('pull-requests.drafts.store', [$workspace, $repository, $pullRequest['id']]) }}"
     data-viewed-url="{{ route('pull-requests.viewed', [$workspace, $repository, $pullRequest['id']]) }}"
     data-file-url="{{ route('pull-requests.file', [$workspace, $repository, $pullRequest['id']]) }}"
     data-source-commit="{{ $pullRequest['source']['commit']['hash'] ?? '' }}"
     data-source-repository="{{ $pullRequest['source']['repository']['full_name'] ?? "$workspace/$repository" }}"
     data-review-state-url="{{ route('pull-requests.review-state', [$workspace, $repository, $pullRequest['id']]) }}"
     data-publish-url="{{ route('pull-requests.drafts.publish', [$workspace, $repository, $pullRequest['id']]) }}">

    {{-- Out of view once the page is scrolled: the heading then turns compact. --}}
    <div class="review-header-sentinel" aria-hidden="true"></div>
    <header class="review-header">
        @include('pull-requests.partials.review-actions')
        {{-- Viewed count, and jumping between the files not viewed yet (also n / p). --}}
        <div class="review-file-nav buttons are-small has-addons">
            <span class="button is-static js-viewed-count" title="Files marked as viewed"></span>
            <button type="button" class="button js-file-prev" title="Previous file not viewed yet (p)">↑ Previous file</button>
            <button type="button" class="button js-file-next" title="Next file not viewed yet (n)">↓ Next file</button>
        </div>
        {{-- Show changes in whitespace only as unchanged (also w). Reloads the page with ?ignore_whitespace=1. --}}
        <div class="review-whitespace-toggle buttons are-small">
            <a class="button js-toggle-whitespace @if($ignoreWhitespace) is-selected @endif" aria-pressed="{{ $ignoreWhitespace ? 'true' : 'false' }}"
               href="{{ route('pull-requests.review', [$workspace, $repository, $pullRequest['id']] + ($ignoreWhitespace ? [] : ['ignore_whitespace' => 1])) }}"
               title="{{ $ignoreWhitespace ? 'Changes in whitespace only are hidden. Click to show them (w)' : 'Hide changes in whitespace only: indentation and spacing (w)' }}">Ignore whitespace</a>
        </div>
        {{-- Hide or show the side columns (also [ and ]). --}}
        <div class="review-pane-toggles buttons are-small has-addons">
            <button type="button" class="button js-toggle-pane" data-side="left" aria-pressed="true" title="Hide or show the file list ([)">Files</button>
            <button type="button" class="button js-toggle-pane" data-side="right" aria-pressed="true" title="Hide or show the comments column (])">
                Comments <span class="tag is-warning is-rounded js-drafts-count" title="Not sent yet">{{ $comments->unsentCount }}</span>
            </button>
        </div>
        <p class="review-repo">
            {{ $workspace }}/{{ $repository }} · #{{ $pullRequest['id'] }}
            @if($issueKey)
                ·
                @if($issueUrl)
                    <a href="{{ $issueUrl }}" target="_blank" rel="noopener noreferrer">{{ $issueKey }}</a>
                @else
                    {{ $issueKey }}
                @endif
                @if($issueSummary) <span class="has-text-grey">{{ $issueSummary }}</span> @endif
            @endif
        </p>
        <h1 class="title is-4">
            {{ $pullRequest['title'] }}
            @if($pullRequest['draft'] ?? false) <span class="tag is-light">Draft PR</span> @endif
        </h1>
        <p class="review-meta">
            <img class="avatar avatar-small" src="{{ $pullRequest['author']['links']['avatar']['href'] ?? '' }}" alt="">
            {{ $pullRequest['author']['display_name'] ?? '' }}
            wants to merge <code>{{ $pullRequest['source']['branch']['name'] }}</code>
            into <code>{{ $pullRequest['destination']['branch']['name'] }}</code>
            · updated {{ Carbon::parse($pullRequest['updated_on'])->diffForHumans() }}
            @if($pipelineState) · pipeline <span class="pipeline-label pipeline-{{ strtolower($pipelineState) }}">{{ strtolower($pipelineState) }}</span> @endif
            · <a href="{{ $bitbucketUrl }}" target="_blank" rel="noopener noreferrer">Open in Bitbucket</a>
        </p>
        @include('pull-requests.partials.participants')
    </header>

    <div class="review-layout">
        {{-- Drag (or focus and use the arrow keys) to resize the side columns; double-click resets. --}}
        <div class="review-resizer review-resizer-left js-resizer" data-side="left" role="separator" aria-orientation="vertical"
             aria-label="Resize the file list" tabindex="0" title="Drag to resize. Double-click to reset."></div>
        <div class="review-resizer review-resizer-right js-resizer" data-side="right" role="separator" aria-orientation="vertical"
             aria-label="Resize the unsent comments column" tabindex="0" title="Drag to resize. Double-click to reset."></div>
        <aside class="review-sidebar">
            <div class="files-panel box">
                <div class="files-title">
                    <strong>{{ count($files) }} {{ Str::plural('file', count($files)) }}</strong>
                    <span class="has-text-success">+{{ array_sum(array_column($files, 'additions')) }}</span>
                    <span class="has-text-danger">−{{ array_sum(array_column($files, 'deletions')) }}</span>
                    <span class="files-actions">
                        <button type="button" class="button is-small is-text js-expand-all">Expand</button>
                        <button type="button" class="button is-small is-text js-collapse-all">Collapse</button>
                    </span>
                </div>
                @include('pull-requests.partials.tree', ['node' => $tree])
            </div>
        </aside>

        <main class="review-main">
            @if(trim($pullRequest['description'] ?? '') !== '')
                <section class="box review-description content">
                    {!! Str::markdown($pullRequest['description'], ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}
                </section>
            @endif

            <section class="box general-comments">
                <h2 class="subtitle is-6">Comments on the pull request</h2>
                <div class="threads js-general-threads">
                    @foreach($comments->general as $thread)
                        @include('pull-requests.partials.thread')
                    @endforeach
                </div>
                <button type="button" class="button is-small js-add-general-comment">Add a comment</button>
            </section>

            @foreach($files as $index => $file)
                @php
                    $lineCount = array_sum(array_map(fn($hunk) => count($hunk['lines']), $file['hunks']));
                    $collapsed = $lineCount > $collapseLines || $file['status'] === Diff::DELETED;
                @endphp
                <section class="diff-file @if($collapsed || $file['viewed']) is-collapsed @endif @if($file['viewed']) is-viewed @endif"
                         id="file-{{ $index }}" data-path="{{ $file['path'] }}" data-version="{{ $file['version'] }}">
                    <header class="diff-file-header">
                        <button type="button" class="diff-file-toggle js-toggle-file" aria-label="Show or hide file"></button>
                        <span class="file-status status-{{ $file['status'] }}">{{ $file['status'] }}</span>
                        <span class="diff-file-path">
                            @if($file['status'] === Diff::RENAMED && $file['old_path'])
                                <span class="has-text-grey">{{ $file['old_path'] }} →</span>
                            @endif
                            {{ $file['path'] }}
                        </span>
                        <span class="diff-file-stats">
                            <span class="has-text-success">+{{ $file['additions'] }}</span>
                            <span class="has-text-danger">−{{ $file['deletions'] }}</span>
                        </span>
                        @if($file['changed_since_viewed'])
                            <span class="tag is-warning is-light is-small js-changed-since-viewed" title="You marked this file as viewed, and it has changed since.">Changed since viewed</span>
                        @endif
                        @if(!$file['binary'] && $file['hunks'] && in_array($file['status'], [Diff::MODIFIED, Diff::RENAMED], true))
                            <button type="button" class="button is-small is-text js-file-view" data-view="full" aria-pressed="false" title="Show the whole file with the changes in it">Full file</button>
                            <button type="button" class="button is-small is-text js-file-view" data-view="new" aria-pressed="false" title="Show only the new version of the file, changed lines marked by the line numbers">New version</button>
                        @endif
                        <button type="button" class="button is-small is-text js-add-file-comment" title="Comment on the whole file">Comment on file</button>
                        <label class="viewed-toggle" title="Mark as viewed: the file is hidden until you open it again, and stays marked until it changes">
                            <input type="checkbox" class="js-viewed" @checked($file['viewed'])> Viewed
                        </label>
                    </header>

                    <div class="diff-file-body">
                        <div class="file-comments">
                            <div class="threads js-file-threads">
                                @foreach($comments->fileThreads[$file['path']] ?? [] as $entry)
                                    @include('pull-requests.partials.thread', ['thread' => $entry['thread'] + ['label' => $entry['label']]])
                                @endforeach
                            </div>
                        </div>

                        @if($file['binary'])
                            <p class="diff-note">Binary file, not shown.</p>
                        @elseif($file['whitespace_only'])
                            <p class="diff-note">Only whitespace changes, hidden.</p>
                        @elseif(!$file['hunks'])
                            <p class="diff-note">No content changes{{ $file['status'] === Diff::RENAMED ? ' (renamed only)' : '' }}.</p>
                        @else
                            <p class="help is-danger diff-full-error js-full-file-error" hidden></p>
                            <table class="diff-table">
                                <colgroup><col class="col-ln"><col class="col-ln"><col></colgroup>
                                @foreach($file['hunks'] as $hunk)
                                    <tbody class="diff-hunk" data-old-start="{{ $hunk['old_start'] }}" data-old-count="{{ $hunk['old_count'] }}"
                                           data-new-start="{{ $hunk['new_start'] }}" data-new-count="{{ $hunk['new_count'] }}">
                                        <tr class="hunk-header"><td colspan="3">{{ $hunk['header'] }}</td></tr>
                                        @foreach($hunk['lines'] as $line)
                                            @if($line['type'] === 'meta')
                                                <tr class="line line-meta"><td></td><td></td><td class="code">{{ $line['text'] }}</td></tr>
                                                @continue
                                            @endif
                                            @php
                                                $side = $line['type'] === 'del' ? 'old' : 'new';
                                                $number = $side === 'old' ? $line['old'] : $line['new'];
                                                $threads = $comments->forLine($file['path'], $line);
                                            @endphp
                                            <tr class="line line-{{ $line['type'] }}" data-side="{{ $side }}" data-line="{{ $number }}">
                                                <td class="ln">{{ $line['old'] }}</td>
                                                <td class="ln">{{ $line['new'] }}</td>
                                                <td class="code"><button type="button" class="add-comment js-add-line-comment" title="Comment on this line">+</button><span class="sign">{{ $line['type'] === 'add' ? '+' : ($line['type'] === 'del' ? '-' : ' ') }}</span><span class="code-text">{{ $line['text'] }}</span></td>
                                            </tr>
                                            @if($threads)
                                                <tr class="comments-row">
                                                    <td colspan="3">
                                                        <div class="threads">
                                                            @foreach($threads as $thread)
                                                                @include('pull-requests.partials.thread')
                                                            @endforeach
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endif
                                        @endforeach
                                    </tbody>
                                @endforeach
                            </table>
                        @endif
                    </div>
                    @if($collapsed)
                        <p class="diff-collapsed-note">
                            {{ $file['status'] === Diff::DELETED ? 'Deleted file' : "Large diff ($lineCount lines)" }} hidden.
                            <button type="button" class="button is-small is-text js-toggle-file">Show</button>
                        </p>
                    @endif
                </section>
            @endforeach
        </main>

        {{-- Comments in their own column right of the diff: unsent ones and sending them, then the threads in Bitbucket. --}}
        <aside class="review-drafts">
            <div class="drafts-panel box">
                <details class="drafts-section js-drafts-section" data-section="unsent" open>
                    <summary class="drafts-title">
                        <strong>Not sent yet</strong>
                        <span class="tag is-warning js-drafts-count">{{ $comments->unsentCount }}</span>
                    </summary>
                    <div class="drafts-section-body">
                        <div class="drafts-nav buttons are-small">
                            <button type="button" class="button js-draft-prev" title="Previous unsent comment (k)">↑ Previous</button>
                            <button type="button" class="button js-draft-next" title="Next unsent comment (j)">↓ Next</button>
                        </div>
                        <ol class="drafts-list js-drafts-list"></ol>
                        <p class="drafts-empty js-drafts-empty">Click <strong>+</strong> next to a line to comment. Comments are saved here, visible only to you, until you send them.</p>
                        <button type="button" class="button is-primary is-small is-fullwidth js-publish" disabled>Send to Bitbucket</button>
                        <p class="help js-publish-help">Adds them all at once to your draft review in Bitbucket. Others see them only after you finish the review there.</p>
                        <label class="checkbox drafts-filter">
                            <input type="checkbox" class="js-hide-sent">
                            Hide comments already in Bitbucket
                        </label>
                        @if($comments->bitbucketDraftCount)
                            <div class="drafts-bitbucket">
                                <p class="help">
                                    {{ $comments->bitbucketDraftCount }} {{ Str::plural('comment', $comments->bitbucketDraftCount) }}
                                    {{ $comments->bitbucketDraftCount === 1 ? 'is' : 'are' }} in your draft review in Bitbucket
                                    (marked <span class="tag is-info is-light is-small">Bitbucket draft</span>), visible only to you.
                                    Finish the review there to publish {{ $comments->bitbucketDraftCount === 1 ? 'it' : 'them' }}, and to approve or request changes.
                                </p>
                                <a class="button is-info is-light is-small is-fullwidth" href="{{ $bitbucketUrl }}" target="_blank" rel="noopener noreferrer">Finish review in Bitbucket</a>
                            </div>
                        @endif
                    </div>
                </details>
                {{-- Threads already in Bitbucket, filled in by review.js from the threads on the diff. --}}
                <details class="drafts-section js-drafts-section" data-section="unresolved" open>
                    <summary class="drafts-title">
                        <strong>Unresolved</strong>
                        <span class="tag is-light js-threads-count" data-section="unresolved">0</span>
                    </summary>
                    <div class="drafts-section-body">
                        <ol class="drafts-list js-threads-list" data-section="unresolved"></ol>
                        <p class="drafts-empty js-threads-empty" data-section="unresolved">No open comment threads in Bitbucket.</p>
                    </div>
                </details>
                <details class="drafts-section js-drafts-section" data-section="resolved">
                    <summary class="drafts-title">
                        <strong>Resolved</strong>
                        <span class="tag is-light js-threads-count" data-section="resolved">0</span>
                    </summary>
                    <div class="drafts-section-body">
                        <ol class="drafts-list js-threads-list" data-section="resolved"></ol>
                        <p class="drafts-empty js-threads-empty" data-section="resolved">No resolved comment threads.</p>
                    </div>
                </details>
            </div>
        </aside>
    </div>
</div>
@endsection
