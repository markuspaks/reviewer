@php
    /** @var array $comment */
    $isUnsent = $comment['local'] ?? false;
    $isBitbucketDraft = !$isUnsent && ($comment['pending'] ?? false) && !($comment['deleted'] ?? false);
    $isOwn = ($comment['user']['uuid'] ?? null) === $currentUserUuid;
    $raw = $comment['content']['raw'] ?? '';
    // Which comments can be changed here: unsent ones, and the user's own pending ones in Bitbucket.
    $editable = match (true) {
        $isUnsent => 'draft',
        $isBitbucketDraft && $isOwn => 'comment',
        default => null,
    };
@endphp
<article class="comment @if($isUnsent) is-unsent @elseif($isBitbucketDraft) is-bitbucket-draft @endif @if($isOwn) is-own @endif"
         id="comment-{{ $comment['id'] }}" data-comment-id="{{ $comment['id'] }}"
         @if($editable) data-editable="{{ $editable }}" @endif @if($isUnsent) data-draft-id="{{ $comment['draft_id'] }}" @endif>
    @if($comment['deleted'] ?? false)
        <p class="comment-deleted">This comment was deleted.</p>
    @else
        <header class="comment-header">
            @if($comment['agent'] ?? false)
                <span class="agent-avatar" aria-hidden="true">AI</span>
            @else
                <img class="avatar avatar-small" src="{{ $comment['user']['links']['avatar']['href'] ?? '' }}" alt="">
            @endif
            <strong>{{ $comment['user']['display_name'] ?? 'Unknown' }}</strong>
            <time datetime="{{ $comment['created_on'] }}" title="{{ $comment['created_on'] }}">
                {{ \Illuminate\Support\Carbon::parse($comment['created_on'])->diffForHumans() }}
            </time>
            @if($isUnsent)
                @if($comment['agent'] ?? false)
                    <span class="tag is-link is-light is-small" title="Added by an AI agent through MCP. Check it before sending.">Agent</span>
                @endif
                <span class="tag is-warning is-small" title="Saved here, visible only to you. Send it from the panel on the left.">Not sent</span>
            @elseif($isBitbucketDraft)
                <span class="tag is-info is-light is-small" title="Pending in your draft review in Bitbucket, visible only to you. Finish the review in Bitbucket to publish it.">Bitbucket draft</span>
            @endif
            @if($editable)
                <span class="comment-actions">
                    <button type="button" class="button is-small is-text js-edit-comment">Edit</button>
                    <button type="button" class="button is-small is-text has-text-danger js-delete-comment">Delete</button>
                </span>
            @endif
        </header>
        <div class="comment-body content">
            {!! \Illuminate\Support\Str::markdown($raw, ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}
        </div>
        <textarea class="comment-raw" hidden>{{ $raw }}</textarea>
    @endif
</article>
