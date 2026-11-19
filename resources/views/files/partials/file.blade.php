{{-- One agent file: name and size; opens to edit. --}}
<details class="agent-file" id="file-{{ $file->id }}">
    <summary>
        <strong>{{ $file->name }}</strong>
        <span class="has-text-grey">
            · {{ Number::fileSize(strlen($file->content)) }} · updated {{ $file->updated_at->diffForHumans() }}
            @if($file->updated_by) by {{ $file->updated_by }} @endif
        </span>
    </summary>
    <form action="{{ url("files/{$file->id}") }}" method="post" class="agent-file-edit">
        @csrf
        @method('PUT')
        <div class="columns">
            <div class="column is-4">
                <div class="select is-fullwidth is-small">
                    <select name="repository" aria-label="For">
                        <option value="">All repositories (global)</option>
                        @foreach($repositories as $repository)
                            <option value="{{ $repository }}" @selected($file->repository === $repository)>{{ $repository }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="column">
                <input class="input is-small" type="text" name="name" value="{{ $file->name }}" required maxlength="255" aria-label="Name">
            </div>
        </div>
        <textarea class="textarea agent-file-content" name="content" rows="14" required maxlength="{{ $maxLength }}" aria-label="Content">{{ $file->content }}</textarea>
        <div class="buttons are-small agent-file-buttons">
            <button class="button is-primary" type="submit">Save</button>
            <button class="button is-danger is-light" type="submit" form="delete-file-{{ $file->id }}">Delete</button>
        </div>
    </form>
    <form action="{{ url("files/{$file->id}") }}" method="post" id="delete-file-{{ $file->id }}"
          onsubmit="return confirm('Delete {{ addslashes($file->name) }}?')">
        @csrf
        @method('DELETE')
    </form>
</details>
