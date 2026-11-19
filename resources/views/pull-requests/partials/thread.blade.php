@php
    $isUnsent = $thread['root']['local'] ?? false;
    // Threads already in Bitbucket can be resolved there; not unsent or pending ones, nor one whose first comment is deleted.
    $resolvable = !$isUnsent && !($thread['root']['pending'] ?? false) && !($thread['root']['deleted'] ?? false);
    $isResolved = $resolvable && isset($thread['root']['resolution']);
@endphp
<div class="thread @if($isUnsent) is-unsent @endif @if($isResolved) is-resolved @endif" data-thread-id="{{ $thread['root']['id'] }}" @isset($thread['label']) data-label="{{ $thread['label'] }}" @endisset>
    @isset($thread['label'])
        <p class="thread-label">{{ $thread['label'] }}</p>
    @endisset
    @include('pull-requests.partials.comment', ['comment' => $thread['root']])
    <div class="replies">
        @foreach($thread['replies'] as $reply)
            @include('pull-requests.partials.comment', ['comment' => $reply])
        @endforeach
    </div>
    {{-- Replies go to comments already in Bitbucket; an unsent comment is edited instead. --}}
    @unless($isUnsent)
        <div class="thread-footer">
            <button type="button" class="button is-small is-light js-reply">Reply</button>
            @if($resolvable)
                <button type="button" class="button is-small is-text js-expand-thread">Show thread</button>
                @include('pull-requests.partials.thread-resolution', ['root' => $thread['root']])
            @endif
        </div>
    @endunless
</div>
