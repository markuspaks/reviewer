{{--
    Pipeline state icon as inline SVG: sharp at any size and transparent on light and dark backgrounds.
    $state: PullRequest::PIPELINE_STATE_* ("successful", "failed", "running"). Emails use the PNGs in public/ instead,
    because many email clients don't show SVG.
--}}
@php $size ??= 18; @endphp
<svg class="pipeline-svg" width="{{ $size }}" height="{{ $size }}" viewBox="0 0 20 20" role="img" aria-label="Pipeline {{ $state }}">
    <title>{{ $state }}</title>
    @switch($state)
        @case(\App\Models\PullRequest::PIPELINE_STATE_SUCCESSFUL)
            <circle cx="10" cy="10" r="9.5" fill="#00875A"/>
            <path d="M5.9 10.3l2.7 2.7 5.5-5.6" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
            @break
        @case(\App\Models\PullRequest::PIPELINE_STATE_FAILED)
            <rect x="3.6" y="3.6" width="12.8" height="12.8" rx="2.6" transform="rotate(45 10 10)" fill="#DE350B"/>
            <path d="M10 5.6v5.2" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/>
            <circle cx="10" cy="14.1" r="1.25" fill="#fff"/>
            @break
        @default
            <circle cx="10" cy="10" r="9.5" fill="#0065FF"/>
            <path d="M10 5.2V10l3 2.6" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
    @endswitch
</svg>
