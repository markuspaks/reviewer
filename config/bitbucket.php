<?php

declare(strict_types=1);

/*
 * This file is part of Laravel Bitbucket.
 *
 * (c) Graham Campbell <hello@gjcampbell.co.uk>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Default Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the connections below you wish to use as
    | your default connection for all work. Of course, you may use many
    | connections at once using the manager class.
    |
    */

    // The app's own account (BITBUCKET_EMAIL / BITBUCKET_API_TOKEN). Users' calls go through BitbucketUserApi instead.
    'default' => 'alternative',

    /*
    |--------------------------------------------------------------------------
    | Bitbucket Connections
    |--------------------------------------------------------------------------
    |
    | Here are each of the connections setup for your application. Example
    | configuration has been included, but you may add as many connections as
    | you would like. Note that the 5 supported authentication methods are:
    | "jwt", "none", "oauth", "private", and "token".
    |
    */

    'connections' => [

        // Used by cron (reviewer:sync) and MCP agents: Atlassian account email + API token, sent as HTTP basic auth.
        // BITBUCKET_USERNAME / BITBUCKET_PASSWORD are the old names, still read as a fallback.
        'alternative' => [
            'method'   => 'password',
            'username' => env('BITBUCKET_EMAIL', env('BITBUCKET_USERNAME')),
            'password' => env('BITBUCKET_API_TOKEN', env('BITBUCKET_PASSWORD')),
            // 'backoff'  => false,
            // 'cache'    => false,
            // 'url'      => null,
        ],

        'jwt' => [
            'method'       => 'jwt',
            'token'        => 'your-jwt-token',
            // 'backoff'      => false,
            // 'cache'        => false,
            // 'version'      => 'v3',
            // 'enterprise'   => false,
        ],

        'private' => [
            'method'     => 'private',
            'appId'      => 'your-bitbucket-app-id',
            'keyPath'    => 'your-private-key-path',
            // 'key'        => 'your-private-key-content',
            // 'passphrase' => 'your-private-key-passphrase'
            // 'backoff'    => false,
            // 'cache'      => false,
            // 'version'    => 'v3',
            // 'enterprise' => false,
        ],

        'none' => [
            'method'     => 'none',
            // 'backoff'    => false,
            // 'cache'      => false,
            // 'version'    => 'v3',
            // 'enterprise' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Cache
    |--------------------------------------------------------------------------
    |
    | Here are each of the cache configurations setup for your application.
    | Only the "illuminate" driver is provided out of the box. Example
    | configuration has been included.
    |
    */

    'cache' => [

        'main' => [
            'driver'    => 'illuminate',
            'connector' => null, // null means use default driver
            // 'min'       => 43200,
            // 'max'       => 172800
        ],

        'bar' => [
            'driver'    => 'illuminate',
            'connector' => 'redis', // config/cache.php
            // 'min'       => 43200,
            // 'max'       => 172800
        ],

    ],

];
