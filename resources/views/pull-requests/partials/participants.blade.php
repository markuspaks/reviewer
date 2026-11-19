<p class="review-participants js-participants">
    @foreach($pullRequest['participants'] ?? [] as $participant)
        <img class="avatar @if($participant['state'] === 'approved')approved @elseif($participant['state'] === 'changes_requested')changes @endif"
             src="{{ $participant['user']['links']['avatar']['href'] ?? '' }}"
             alt="{{ $participant['user']['display_name'] ?? '' }}"
             title="{{ $participant['user']['display_name'] ?? '' }}{{ $participant['state'] ? ' · ' . str_replace('_', ' ', $participant['state']) : '' }}">
    @endforeach
</p>
