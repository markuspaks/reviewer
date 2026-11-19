<!DOCTYPE HTML>
<html lang="en">
<head>
    <meta charset=utf-8>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@hasSection('title')@yield('title') · @endif{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    {{-- Pages that need the full window width (the review page) set @section('fluid', true). --}}
    <div class="container @hasSection('fluid') is-fluid @endif">
        <nav class="navbar" role="navigation" aria-label="main navigation">
            <div class="navbar-brand">
                <a class="navbar-item" href="{{ url('') }}">
                    Reviewer
                </a>

                <a role="button" class="navbar-burger" aria-label="menu" aria-expanded="false" data-target="navbar">
                    <span aria-hidden="true"></span>
                    <span aria-hidden="true"></span>
                    <span aria-hidden="true"></span>
                </a>
            </div>

            <div id="navbar" class="navbar-menu">
                <div class="navbar-start">
                    <a class="navbar-item" href="{{ url('') }}">
                        Reviews
                    </a>

                    <a class="navbar-item" href="{{ url('notifications') }}">
                        Send Emails
                    </a>

                    <a class="navbar-item" href="{{ url('files') }}">
                        Files
                    </a>

                    <a class="navbar-item" href="{{ url('configuration') }}">
                        Configuration
                    </a>
                </div>

                <div class="navbar-end">
                    @php
                        $lastSyncedAt = \App\Models\Setting::getValue(\App\Services\Sync::LAST_SYNCED_AT);
                        $lastSyncError = \App\Models\Setting::getValue(\App\Services\Sync::LAST_SYNC_ERROR);
                    @endphp
                    <div class="navbar-item sync-status">
                        <span @if($lastSyncError) class="has-text-danger" title="{{ $lastSyncError }}" @endif>
                            @if($lastSyncedAt)
                                Updated {{ \Illuminate\Support\Carbon::parse($lastSyncedAt)->diffForHumans() }}
                            @else
                                Not synced yet
                            @endif
                            @if($lastSyncError) (last refresh failed) @endif
                        </span>
                    </div>
                    <div class="navbar-item">
                        {{ auth()->user()?->name }} &nbsp;
                        <form action="{{ route('logout') }}" method="post" class="logout-form">
                            @csrf
                            <button class="button is-small is-text" type="submit">Log out</button>
                        </form>
                    </div>
                </div>
            </div>
        </nav>

        @if(session('warning'))
            <div class="notification is-warning is-light">{{ session('warning') }}</div>
        @endif

        @if(session('status'))
            <div class="notification is-success is-light">{{ session('status') }}</div>
        @endif

        <div style="padding-top: 20px">
            @yield('content')
        </div>
    </div>
</body>
</html>
