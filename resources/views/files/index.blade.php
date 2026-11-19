@extends('layouts.main')

@section('title', 'Files')

@php use Illuminate\Support\Number; @endphp

@section('content')
<div class="agent-files">
    <h1 class="title is-4">Files for agents</h1>
    <p class="subtitle is-6">
        Rules and notes AI agents get through the MCP server together with a ticket's pull requests:
        global files for every pull request, repository files only for pull requests in that repository.
        <a href="{{ url('configuration') }}#mcp">Connect an agent</a>.
    </p>

    <section class="box">
        <h2 class="subtitle is-5">Add a file</h2>
        <form action="{{ url('files') }}" method="post" class="js-agent-file-form">
            @csrf
            <div class="columns">
                <div class="column is-4">
                    <div class="field">
                        <label class="label" for="new-repository">For</label>
                        <div class="select is-fullwidth">
                            <select name="repository" id="new-repository">
                                <option value="">All repositories (global)</option>
                                @foreach($repositories as $repository)
                                    <option value="{{ $repository }}" @selected(old('repository') === $repository)>{{ $repository }}</option>
                                @endforeach
                            </select>
                        </div>
                        @error('repository') <p class="help is-danger">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="column">
                    <div class="field">
                        <label class="label" for="new-name">Name</label>
                        <input class="input" type="text" name="name" id="new-name" value="{{ old('name') }}"
                               placeholder="review-rules.md" required maxlength="255">
                        @error('name') <p class="help is-danger">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="column is-narrow">
                    <div class="field">
                        <label class="label">&nbsp;</label>
                        <div class="file">
                            <label class="file-label">
                                <input class="file-input js-load-file" type="file" accept=".md,.txt,.markdown,.json,.yml,.yaml,.xml,text/*">
                                <span class="file-cta"><span class="file-label">Load from a file…</span></span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="field">
                <label class="label" for="new-content">Content</label>
                <textarea class="textarea agent-file-content" name="content" id="new-content" rows="10" required
                          maxlength="{{ $maxLength }}" placeholder="# Review rules&#10;&#10;- …">{{ old('content') }}</textarea>
                @error('content') <p class="help is-danger">{{ $message }}</p> @enderror
            </div>
            <button class="button is-primary" type="submit">Add file</button>
        </form>
    </section>

    <section class="box">
        <h2 class="subtitle is-5">Global <span class="tag is-light">{{ count($globalFiles) }}</span></h2>
        @forelse($globalFiles as $file)
            @include('files.partials.file')
        @empty
            <p class="has-text-grey">No global files yet.</p>
        @endforelse
    </section>

    <section class="box">
        <h2 class="subtitle is-5">Repositories <span class="tag is-light">{{ $repositoryFiles->flatten()->count() }}</span></h2>
        @forelse($repositoryFiles as $repository => $files)
            <h3 class="agent-files-repository">{{ $repository }}</h3>
            @foreach($files as $file)
                @include('files.partials.file')
            @endforeach
        @empty
            <p class="has-text-grey">No repository files yet. Choose a repository under <em>For</em> when adding a file.</p>
        @endforelse
    </section>
</div>
@endsection
