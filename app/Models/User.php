<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A Reviewer user. uuid is their Bitbucket account uuid (without braces): their unsent comments, viewed files and
 * Bitbucket participation are keyed by it. Created with `php artisan reviewer:user`.
 *
 * @property string $uuid
 * @property string $name
 * @property string|null $email login email, also where notification emails go
 * @property string|null $bitbucket_email Atlassian account email for the API token, when it differs from email
 * @property string|null $bitbucket_api_token personal Atlassian API token, encrypted
 * @property string|null $mcp_token sha256 of the MCP token
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasUuids, HasFactory, Notifiable;

    public $primaryKey = 'uuid';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'bitbucket_api_token',
        'mcp_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'bitbucket_api_token' => 'encrypted',
        ];
    }

    public function hasBitbucketToken(): bool
    {
        return $this->bitbucket_api_token !== null && $this->bitbucket_api_token !== '';
    }

    /**
     * The email the API token is used with (Atlassian account email).
     */
    public function bitbucketEmail(): ?string
    {
        return $this->bitbucket_email ?: $this->email;
    }
}
