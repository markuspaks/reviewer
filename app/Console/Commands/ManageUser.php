<?php

namespace App\Console\Commands;

use App\Models\BitbucketUser;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Validator;

/**
 * Create a login, or set a new password for one. Users are Bitbucket workspace members (as synced), so their
 * unsent comments, viewed files and approvals line up with Bitbucket.
 */
class ManageUser extends Command
{
    protected $signature = 'reviewer:user
        {email : Login email}
        {--member= : Bitbucket member (nickname or display name), when the user does not exist yet}
        {--generate : Generate a password and print it, instead of asking for one}';

    protected $description = 'Create a Reviewer login, or set a new password for one';

    public function handle(): int
    {
        $email = trim($this->argument('email'));
        if (Validator::make(['email' => $email], ['email' => 'email'])->fails()) {
            $this->error("\"$email\" isn't an email address.");
            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();
        if ($user === null) {
            $user = $this->newUser($email);
            if ($user === null) {
                return self::FAILURE;
            }
        } elseif ($this->option('member')) {
            $this->warn("$email already exists ({$user->name}); --member is ignored.");
        }

        $password = $this->option('generate') ? Str::password(20, symbols: false) : $this->askPassword();
        if ($password === null) {
            return self::FAILURE;
        }

        $created = !$user->exists;
        $user->password = $password;
        $user->save();

        $this->info(($created ? 'Created' : 'Updated') . " the login for {$user->name} ($email).");
        if ($this->option('generate')) {
            $this->line("Password: $password");
        }
        $this->line('They add their own Bitbucket API token on the Configuration page after logging in.');

        return self::SUCCESS;
    }

    protected function newUser(string $email): ?User
    {
        $members = BitbucketUser::orderBy('nickname')->get();
        if ($members->isEmpty()) {
            $this->error('No Bitbucket members synced yet: run php artisan reviewer:sync first.');
            return null;
        }

        $label = fn(BitbucketUser $member) => trim(($member->data['display_name'] ?? $member->nickname) . ' (' . $member->nickname . ')');
        $wanted = $this->option('member');
        if ($wanted !== null) {
            $member = $members->first(fn(BitbucketUser $member) => strcasecmp($member->nickname, $wanted) === 0
                || strcasecmp($member->data['display_name'] ?? '', $wanted) === 0);
            if ($member === null) {
                $this->error("No Bitbucket member \"$wanted\". Members: " . $members->map($label)->join(', '));
                return null;
            }
        } elseif ($this->input->isInteractive()) {
            $choice = $this->choice("$email is new. Which Bitbucket member is it?", $members->map($label)->values()->all());
            $member = $members->first(fn(BitbucketUser $member) => $label($member) === $choice);
        } else {
            $this->error("$email is new: pass --member=<Bitbucket nickname>.");
            return null;
        }

        $uuid = trim($member->uuid, '{}');
        if (($existing = User::find($uuid)) !== null) {
            if ($existing->email && $existing->email !== $email) {
                $this->error("{$label($member)} already has a login with another email ({$existing->email}). Use that email, or change it on the Configuration page.");
                return null;
            }
            $existing->email = $email;
            return $existing;
        }

        $user = new User();
        $user->uuid = $uuid;
        $user->name = $member->data['display_name'] ?? $member->nickname;
        $user->email = $email;

        return $user;
    }

    protected function askPassword(): ?string
    {
        if (!$this->input->isInteractive()) {
            $this->error('Run it interactively to type a password, or pass --generate.');
            return null;
        }

        $password = (string) $this->secret('Password (at least 8 characters)');
        $validator = Validator::make(['password' => $password], ['password' => ['required', Password::min(8)]]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first('password'));
            return null;
        }
        if ($this->secret('Repeat the password') !== $password) {
            $this->error("The passwords don't match.");
            return null;
        }

        return $password;
    }
}
