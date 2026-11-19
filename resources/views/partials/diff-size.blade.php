{{-- Diff size: files changed, lines added and removed. $size is null until the sync has counted it. --}}
@if($size)
    <span class="diff-size" title="{{ number_format($size['files']) }} {{ $size['files'] === 1 ? 'file' : 'files' }}, {{ number_format($size['lines_added'] + $size['lines_removed']) }} {{ $size['lines_added'] + $size['lines_removed'] === 1 ? 'line' : 'lines' }} changed">
        <span class="diff-size-files">{{ number_format($size['files']) }} {{ $size['files'] === 1 ? 'file' : 'files' }}</span>
        <span class="diff-size-added">+{{ number_format($size['lines_added']) }}</span>
        <span class="diff-size-removed">−{{ number_format($size['lines_removed']) }}</span>
    </span>
@else
    <span class="diff-size has-text-grey-light" title="Not synced yet">—</span>
@endif
