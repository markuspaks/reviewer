@extends('layouts.main')

@section('content')

    <div class="counters">
        @foreach($users as $user)
            <a class="counter" href="{{ route('reviews.index', ['reviewer' => $user['uuid']]) }}">
                <img class="avatar" src="{{ $user['links']['avatar']['href'] }}"
                     alt="{{ $user['nickname'] }}"
                     title="{{ $user['nickname'] }}"> {{ count($user['pull_requests']) }}
            </a>
        @endforeach
    </div>

    <div>
        <table class="reviews">
            @foreach($pullRequests as $pullRequest)
                <?php $pullRequestData = $pullRequest->getData(); ?>
                <tr>
                    <td>
                        <img class="avatar" src="{{ $pullRequestData['author']['links']['avatar']['href'] }}"
                             alt="{{ $pullRequestData['author']['nickname'] }}"
                             title="{{ $pullRequestData['author']['nickname'] }}">
                    </td>
                    <td>{{ $pullRequestData['source']['repository']['full_name'] }}</td>
                    <td>
                        <a href="{{ $pullRequestData['links']['html']['href'] }}">{{ $pullRequestData['title'] }}</a>
                    </td>
                    <td>
                        @foreach($pullRequestData['participants'] as $participant)
                            <img class="avatar @if($participant['state'] === 'approved')approved @elseif($participant['state'] === 'changes_requested')changes @endif"
                                 src="{{ $participant['user']['links']['avatar']['href'] }}"
                                 alt="{{ $participant['user']['nickname'] }}"
                                 title="{{ $participant['user']['nickname'] }}">
                        @endforeach
                    </td>
                    <td>
                        {{ (new \Carbon\Carbon($pullRequestData['updated_on']))->diffForHumans() }}
                    </td>
                    <td>
                        @foreach($pullRequest->getAssignees() as $assignee)
                            <img class="avatar" src="{{ $assignee['links']['avatar']['href'] }}"
                                 alt="{{ $assignee['nickname'] }}"
                                 title="{{ $assignee['nickname'] }}">
                        @endforeach
                    </td>
                </tr>
            @endforeach
        </table>
        Total {{ count($pullRequests) }} pull @if(count($pullRequests) !== 1) requests. @else request. @endif
    </div>

@endsection
