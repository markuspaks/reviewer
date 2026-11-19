{{-- The logged-in user's own settings: Bitbucket API token and password. --}}
@php
    $user = auth()->user();
    $scopes = \App\Http\Controllers\AccountController::TOKEN_SCOPES;
@endphp
<section class="box account-settings" id="bitbucket">
    <h2 class="subtitle is-5">Your Bitbucket API token</h2>
    <p>
        The review page reads pull requests and sends your comments, approvals and change requests to Bitbucket with
        this token, so they appear under your name. Only you use it; it is stored encrypted.
    </p>

    @if($user->hasBitbucketToken())
        <p class="account-token-status">
            <span class="tag is-success is-light">Token saved</span>
            used with {{ $user->bitbucketEmail() }}
        </p>
    @else
        <div class="notification is-warning is-light">
            Add your token to open pull requests for review.
        </div>
    @endif

    <details @if(!$user->hasBitbucketToken() || $errors->has('bitbucket_api_token')) open @endif>
        <summary>{{ $user->hasBitbucketToken() ? 'Replace the token' : 'How to create one' }}</summary>
        <ol class="account-steps">
            <li>
                Open <a href="https://id.atlassian.com/manage-profile/security/api-tokens" target="_blank" rel="noopener noreferrer">Atlassian API tokens</a>,
                signed in as <strong>yourself</strong> (the account you use in Bitbucket, not a shared one).
            </li>
            <li>Click <strong>Create API token with scopes</strong> (not the plain <em>Create API token</em>).</li>
            <li>
                Name it, e.g. <code>Reviewer</code>, and pick an expiry date (at most a year). When it expires, create a new
                one and replace it here.
            </li>
            <li>Choose the <strong>Bitbucket</strong> app and click <strong>Next</strong>.</li>
            <li>
                Tick these scopes (the search box helps), then click <strong>Next</strong>:
                <ul class="account-scopes">
                    @foreach($scopes as $scope => $purpose)
                        <li><code>{{ $scope }}</code> — {{ $purpose }}</li>
                    @endforeach
                </ul>
            </li>
            <li>Click <strong>Create token</strong> and copy it. Atlassian shows it only once.</li>
            <li>
                Paste it below with your Atlassian account email, and click <strong>Check and save</strong>. It is checked
                with Bitbucket before it is saved.
            </li>
        </ol>

        <form action="{{ url('configuration/bitbucket-token') }}" method="post">
            @csrf
            <div class="field">
                <label class="label is-small" for="bitbucket_email">Atlassian account email</label>
                <input class="input is-small" type="email" id="bitbucket_email" name="bitbucket_email"
                       value="{{ old('bitbucket_email', $user->bitbucket_email) }}" placeholder="{{ $user->email }}" autocomplete="off">
                <p class="help">Leave empty if it's your login email ({{ $user->email }}).</p>
            </div>
            <div class="field">
                <label class="label is-small" for="bitbucket_api_token">API token</label>
                <input class="input is-small @error('bitbucket_api_token') is-danger @enderror" type="password" id="bitbucket_api_token"
                       name="bitbucket_api_token" autocomplete="off" required>
                @error('bitbucket_api_token') <p class="help is-danger">{{ $message }}</p> @enderror
            </div>
            <div class="buttons are-small">
                <button class="button is-primary" type="submit">Check and save</button>
            </div>
        </form>
    </details>

    @if($user->hasBitbucketToken())
        <form action="{{ url('configuration/bitbucket-token') }}" method="post" class="account-remove"
              onsubmit="return confirm('Remove your Bitbucket API token? You can\'t review pull requests here until you add one again.')">
            @csrf
            @method('DELETE')
            <button class="button is-danger is-light is-small" type="submit">Remove token</button>
        </form>
    @endif
</section>

<section class="box account-settings" id="account">
    <h2 class="subtitle is-5">Your password</h2>
    <form action="{{ url('configuration/password') }}" method="post">
        @csrf
        @method('PUT')
        <input type="text" name="email" value="{{ $user->email }}" autocomplete="username" hidden>
        <div class="columns">
            <div class="column field">
                <label class="label is-small" for="current_password">Current password</label>
                <input class="input is-small @error('current_password', 'password') is-danger @enderror" type="password"
                       id="current_password" name="current_password" autocomplete="current-password" required>
                @error('current_password', 'password') <p class="help is-danger">{{ $message }}</p> @enderror
            </div>
            <div class="column field">
                <label class="label is-small" for="password">New password</label>
                <input class="input is-small @error('password', 'password') is-danger @enderror" type="password"
                       id="password" name="password" autocomplete="new-password" minlength="8" required>
                @error('password', 'password') <p class="help is-danger">{{ $message }}</p> @enderror
            </div>
            <div class="column field">
                <label class="label is-small" for="password_confirmation">Repeat new password</label>
                <input class="input is-small" type="password" id="password_confirmation" name="password_confirmation"
                       autocomplete="new-password" minlength="8" required>
            </div>
        </div>
        <button class="button is-small" type="submit">Change password</button>
    </form>
</section>
