@extends('layouts.main')

@section('content')

    <form action="{{ url('notifications/emails') }}" method="post">
        @csrf
        <div>
            @foreach($users as $user)
                <div class="columns">
                    <div class="column is-1">
                        <input
                            class="checkbox"
                            type="checkbox"
                            name="send[{{ $user['uuid'] }}]"
                            value="1"
                            @checked(count($user['pull_requests']))
                        >
                    </div>
                    <div class="column is-1">
                        <img class="avatar" src="{{ $user['links']['avatar']['href'] }}"
                             alt="{{ $user['nickname'] }}"
                             title="{{ $user['nickname'] }}">
                    </div>
                    <div class="column">{{ $user['nickname'] }} ({{ count($user['pull_requests']) }})</div>
                </div>
            @endforeach

            <div class="columns">
                <div class="column has-text-right">
                    <button class="button is-success">Send Emails</button>
                </div>
            </div>
        </div>
    </form>

@endsection
