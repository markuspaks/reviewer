{{-- Resolve or reopen a thread in Bitbucket; who resolved it. $root is the thread's first comment. --}}
@php $resolution = $root['resolution'] ?? null; @endphp
<span class="thread-resolution js-thread-resolution">
    @if($resolution)
        <span class="thread-resolved-by has-text-success">
            ✓ Resolved{{ isset($resolution['user']['display_name']) ? ' by ' . $resolution['user']['display_name'] : '' }}
            @isset($resolution['created_on'])
                <time datetime="{{ $resolution['created_on'] }}" title="{{ $resolution['created_on'] }}">{{ \Illuminate\Support\Carbon::parse($resolution['created_on'])->diffForHumans() }}</time>
            @endisset
        </span>
        <button type="button" class="button is-small is-light js-resolve" data-resolved="0" title="Reopen the thread in Bitbucket">Reopen</button>
    @else
        <button type="button" class="button is-small is-light js-resolve" data-resolved="1" title="Resolve the thread in Bitbucket">Resolve</button>
    @endif
</span>
