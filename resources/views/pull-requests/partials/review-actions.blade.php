@php
    $ownState = null;
    foreach ($pullRequest['participants'] ?? [] as $participant) {
        if (($participant['user']['uuid'] ?? null) === $currentUserUuid) {
            $ownState = $participant['state'] ?? null;
        }
    }
    $isAuthor = ($pullRequest['author']['uuid'] ?? null) === $currentUserUuid;
    $isOpen = ($pullRequest['state'] ?? 'OPEN') === 'OPEN';
@endphp
{{-- Approve / Request changes as the user; clicking the one already chosen takes it back. --}}
<div class="review-actions buttons are-small js-review-actions">
    @if($isOpen && !$isAuthor)
        @if($ownState === 'approved')
            <button type="button" class="button is-success js-review-state" data-state="none" title="You approved. Click to take your approval back.">✓ Approved</button>
        @else
            <button type="button" class="button is-success is-outlined js-review-state" data-state="approved" title="Approve this pull request in Bitbucket">Approve</button>
        @endif
        @if($ownState === 'changes_requested')
            <button type="button" class="button is-warning js-review-state" data-state="none" title="You requested changes. Click to take the request back.">Changes requested</button>
        @else
            <button type="button" class="button is-warning is-outlined js-review-state" data-state="changes_requested" title="Request changes in Bitbucket">Request changes</button>
        @endif
    @endif
</div>
