@extends('layouts.main')

@section('content')

    <form action="{{ url('configuration') }}" method="post">
        @csrf
        @method('PUT')
        <div>
            @foreach($users as $user)
                <div class="columns">
                    <div class="column is-1">
                        <img class="avatar" src="{{ $user['links']['avatar']['href'] }}"
                             alt="{{ $user['nickname'] }}"
                             title="{{ $user['nickname'] }}">
                    </div>
                    <div class="column">{{ $user['nickname'] }}</div>
                    <div class="column">
                        <input type="hidden" name="users[{{ $user['uuid'] }}][uuid]" value="{{ $user['uuid'] }}">
                        <input type="hidden" name="users[{{ $user['uuid'] }}][name]" value="{{ $user['nickname'] }}">
                        <input class="input" type="text" name="users[{{ $user['uuid'] }}][email]" value="{{ $user['email'] }}">
                    </div>
                </div>
            @endforeach

            <div class="columns">
                <div class="column has-text-right">
                    <button class="button is-success">Save</button>
                </div>
            </div>
        </div>
    </form>

@endsection
