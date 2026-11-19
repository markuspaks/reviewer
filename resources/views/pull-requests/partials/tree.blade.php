<ul class="file-tree">
    @foreach($node['folders'] as $name => $folder)
        <li class="tree-folder">
            <details open>
                <summary title="{{ $name }}">{{ $name }}</summary>
                @include('pull-requests.partials.tree', ['node' => $folder])
            </details>
        </li>
    @endforeach
    @foreach($node['files'] as $index)
        @php
            $file = $files[$index];
            $commentCount = $comments->countForFile($file['path']);
        @endphp
        <li class="tree-file">
            <a href="#file-{{ $index }}" class="js-tree-link @if($file['viewed']) is-viewed @endif" data-file-index="{{ $index }}" title="{{ $file['path'] }}">
                <span class="file-status status-{{ $file['status'] }}" title="{{ $file['status'] }}">{{ strtoupper($file['status'][0]) }}</span>
                <span class="tree-file-name">{{ basename($file['path']) }}</span>
                <span class="tree-viewed" title="Viewed" aria-label="Viewed">✓</span>
                @if($commentCount)
                    <span class="tree-comments" title="{{ $commentCount }} comment threads">{{ $commentCount }}</span>
                @endif
            </a>
        </li>
    @endforeach
</ul>
