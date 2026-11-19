<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Workspace member, as last synced from Bitbucket.
 *
 * @property string $uuid
 * @property string|null $account_id
 * @property string|null $nickname
 * @property array $data Bitbucket user object
 */
class BitbucketUser extends Model
{
    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['uuid', 'account_id', 'nickname', 'data'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
