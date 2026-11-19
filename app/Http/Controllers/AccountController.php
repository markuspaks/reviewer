<?php

namespace App\Http\Controllers;

use App\Services\BitbucketUserApi;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * The logged-in user's own settings: their Bitbucket API token (the review page calls Bitbucket with it, so comments
 * and approvals are theirs) and their password.
 */
class AccountController extends Controller
{
    /** Scopes the review page needs, with what each is for: for the help text and error messages. */
    public const TOKEN_SCOPES = [
        'read:user:bitbucket' => 'checks the token is yours',
        'read:repository:bitbucket' => 'reads the changed files',
        'read:pullrequest:bitbucket' => 'reads pull requests, diffs and comments',
        'write:pullrequest:bitbucket' => 'sends your comments and approvals',
    ];

    /**
     * Save the token after checking with Bitbucket that it works and belongs to this user.
     */
    public function storeBitbucketToken(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'bitbucket_email' => ['nullable', 'email', 'max:255'],
            'bitbucket_api_token' => ['required', 'string', 'max:1000'],
        ]);
        $user = Auth::user();
        $email = ($data['bitbucket_email'] ?? null) ?: $user->email;
        $token = trim($data['bitbucket_api_token']);

        try {
            $response = BitbucketUserApi::withCredentials($email, $token)->currentUserResponse();
        } catch (ConnectionException) {
            throw $this->tokenError('Could not reach Bitbucket. Try again in a moment.');
        }

        if ($response->status() === 401) {
            throw $this->tokenError("Bitbucket didn't accept this token with $email. Use your Atlassian account email, and an API token with Bitbucket scopes.");
        }
        if ($response->status() === 403) {
            throw $this->tokenError('The token works but is missing scopes. Create it with: ' . implode(', ', array_keys(self::TOKEN_SCOPES)) . '.');
        }
        if (!$response->successful()) {
            throw $this->tokenError('Bitbucket: ' . ($response->json('error.message') ?? 'HTTP ' . $response->status()));
        }

        $tokenUuid = trim((string) $response->json('uuid'), '{}');
        if ($tokenUuid !== trim($user->uuid, '{}')) {
            throw $this->tokenError('This token belongs to Bitbucket user ' . ($response->json('display_name') ?? $tokenUuid) . ', not to you.');
        }

        $user->forceFill([
            'bitbucket_email' => $email === $user->email ? null : $email,
            'bitbucket_api_token' => $token,
        ])->save();

        // Back to the pull request that asked for the token, if any.
        return redirect()->intended(url('configuration') . '#bitbucket')->with('status', 'Bitbucket API token saved. The review page now uses it.');
    }

    public function destroyBitbucketToken(): RedirectResponse
    {
        Auth::user()->forceFill(['bitbucket_email' => null, 'bitbucket_api_token' => null])->save();

        return redirect()->to(url('configuration') . '#bitbucket')->with('status', 'Bitbucket API token removed.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('password', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);
        Auth::user()->forceFill(['password' => $data['password']])->save();

        return redirect()->to(url('configuration') . '#account')->with('status', 'Password changed.');
    }

    protected function tokenError(string $message): ValidationException
    {
        return ValidationException::withMessages(['bitbucket_api_token' => $message])->redirectTo(url('configuration') . '#bitbucket');
    }
}
