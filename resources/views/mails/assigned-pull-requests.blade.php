@component('mail::message')
# {{ $user['nickname'] }}, {{ count($user['pull_requests']) }} pull requests are waiting for you

You need to deal with these pull requests.

@component('mail::table')
    | Author        | Title         | Updated  |
    | ------------- | ------------- | --------:|
    @foreach($user['pull_requests'] as $pullRequest)
        <?php $pullRequestData = $pullRequest->getData(); ?>
    | <img style="width: 30px; border-radius: 50%;" src="{{ $pullRequestData['author']['links']['avatar']['href'] }}" alt="{{ $pullRequestData['author']['nickname'] }}" title="{{ $pullRequestData['author']['nickname'] }}"> | [{{ $pullRequestData['title'] }}]({{ $pullRequestData['links']['html']['href'] }}) | {{ (new \Carbon\Carbon($pullRequestData['updated_on']))->diffForHumans() }} |
    @endforeach
@endcomponent

* If you are owner of pull request, and you have done with all requested changes write 'Needs review' as comment.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
