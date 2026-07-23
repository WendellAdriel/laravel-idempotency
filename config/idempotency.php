<?php

declare(strict_types=1);

use WendellAdriel\Idempotency\Enums\IdempotencyScope;

return [
    /*
    |--------------------------------------------------------------------------
    | Idempotency TTL
    |--------------------------------------------------------------------------
    |
    | Number of seconds an idempotency key should remain valid.
    |
    */
    'ttl' => env('IDEMPOTENCY_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Idempotency Required Header
    |--------------------------------------------------------------------------
    |
    | Determine whether requests must include the configured idempotency
    | header. When disabled, requests without the header pass through.
    |
    */
    'required' => env('IDEMPOTENCY_REQUIRED', true),

    /*
    |--------------------------------------------------------------------------
    | Idempotency Scope
    |--------------------------------------------------------------------------
    |
    | Supported values are: user, ip, and global. The user scope falls back
    | to the request IP address when no authenticated user is available.
    |
    */
    'scope' => env('IDEMPOTENCY_SCOPE', IdempotencyScope::User->value),

    /*
    |--------------------------------------------------------------------------
    | Idempotency Header
    |--------------------------------------------------------------------------
    |
    | This header will be inspected for the client-provided idempotency key.
    |
    */
    'header' => env('IDEMPOTENCY_HEADER', 'Idempotency-Key'),

    /*
    |--------------------------------------------------------------------------
    | Idempotency Request Input
    |--------------------------------------------------------------------------
    |
    | This request input will be inspected when the configured header does
    | not contain a non-empty idempotency key.
    |
    */
    'input' => env('IDEMPOTENCY_INPUT', '_idempotency_key'),

    /*
    |--------------------------------------------------------------------------
    | Idempotency Cache Statuses
    |--------------------------------------------------------------------------
    |
    | Determine which response classes are stored under an idempotency key.
    | Disabling a class leaves the key untouched for those responses, so a
    | client may retry instead of replaying a stored failure for the whole
    | TTL. Disable client_error to free keys used by failed validation.
    |
    */
    'cache_statuses' => [
        'informational' => true,
        'success' => true,
        'redirection' => true,
        'client_error' => true,
        'server_error' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency Lock Timeout
    |--------------------------------------------------------------------------
    |
    | Number of seconds the atomic in-flight lock is held while a request
    | is being processed. If a request takes longer than this value, the
    | lock expires and a concurrent request with same key may proceed.
    | Increase this value for endpoints with long processing times.
    |
    */
    'lock_timeout' => env('IDEMPOTENCY_LOCK_TIMEOUT', 10),
];
