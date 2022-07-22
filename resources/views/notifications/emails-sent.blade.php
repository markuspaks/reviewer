@extends('layouts.main')

@section('content')

    <div>
        Emails sent to users:
    </div>
    <div>
        @foreach($users as $user)
            <div class="columns">
                <div class="column is-1">
                    <img class="avatar" src="{{ $user['links']['avatar']['href'] }}"
                         alt="{{ $user['nickname'] }}"
                         title="{{ $user['nickname'] }}">
                </div>
                <div class="column">{{ $user['nickname'] }} ({{ count($user['pull_requests']) }})</div>
            </div>
        @endforeach
    </div>

@endsection
