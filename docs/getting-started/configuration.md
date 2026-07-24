# Configuration

- [Introduction](#introduction)
- [Time to live](#time-to-live)
- [Required key](#required-key)
- [Scope](#scope)
- [Header name](#header-name)
- [Request input name](#request-input-name)
- [Cache statuses](#cache-statuses)
- [Lock timeout](#lock-timeout)

## Introduction

Laravel Idempotency stores its application-level options in `config/idempotency.php`. You may publish this file with the `idempotency-config` tag:

```shell
php artisan vendor:publish --tag="idempotency-config"
```

The default configuration is intentionally small. It controls stored response lifetime, key input behavior, scope resolution, which responses are stored, and the in-flight lock timeout.

```php
return [
    'ttl' => env('IDEMPOTENCY_TTL', 3600),
    'required' => env('IDEMPOTENCY_REQUIRED', true),
    'scope' => env('IDEMPOTENCY_SCOPE', IdempotencyScope::User->value),
    'header' => env('IDEMPOTENCY_HEADER', 'Idempotency-Key'),
    'input' => env('IDEMPOTENCY_INPUT', '_idempotency_key'),
    'cache_statuses' => [
        'informational' => true,
        'success' => true,
        'redirection' => true,
        'client_error' => true,
        'server_error' => true,
    ],
    'lock_timeout' => env('IDEMPOTENCY_LOCK_TIMEOUT', 10),
];
```

## Time to live

The `ttl` option defines how long a stored response remains available, in seconds:

```php
'ttl' => env('IDEMPOTENCY_TTL', 3600),
```

Use a value long enough for the retry window your clients need. After the TTL expires, the same idempotency key is treated as a new request.

The `ttl` must resolve to a positive integer (`>= 1`). A value of `0` or lower throws an `InvalidArgumentException` when options are resolved, so a misconfigured `IDEMPOTENCY_TTL` fails fast instead of silently disabling deduplication.

## Required key

The `required` option determines whether protected requests must include a non-empty idempotency key in the configured header or request input:

```php
'required' => env('IDEMPOTENCY_REQUIRED', true),
```

When this is `true`, a missing key returns `400 Bad Request`. When this is `false`, requests without a valid key pass through and are not stored.

## Scope

The `scope` option controls how client-provided keys are segmented:

```php
'scope' => env('IDEMPOTENCY_SCOPE', IdempotencyScope::User->value),
```

Supported values are `user`, `ip`, and `global`. See [scopes](../basics/scopes.md) for the behavior of each option.

## Header name

The `header` option defines the preferred request header for the client-provided idempotency key:

```php
'header' => env('IDEMPOTENCY_HEADER', 'Idempotency-Key'),
```

If your client sends `X-Idempotency-Key`, set `IDEMPOTENCY_HEADER=X-Idempotency-Key` or override the header for a single route.

## Request input name

The `input` option defines which request input is inspected when the configured header does not contain a non-empty string:

```php
'input' => env('IDEMPOTENCY_INPUT', '_idempotency_key'),
```

The default works with a hidden form input named `_idempotency_key`. To use `_request_key` instead, set `IDEMPOTENCY_INPUT=_request_key`. The header always takes precedence when both sources contain valid keys.

## Cache statuses

The `cache_statuses` option determines which response categories are stored under an idempotency key:

```php
'cache_statuses' => [
    'informational' => true,
    'success' => true,
    'redirection' => true,
    'client_error' => true,
    'server_error' => true,
],
```

Each key maps to an HTTP status range:

| Category | Status codes |
| --- | --- |
| `informational` | `1xx` |
| `success` | `2xx` |
| `redirection` | `3xx` |
| `client_error` | `4xx` |
| `server_error` | `5xx` |

Every category is enabled by default, which stores every response. Disabling a category leaves the key untouched for those responses: the client may retry with the same key and the route is executed again. Nothing is written to the [key index](../operations/maintenance-commands.md) for a response that is not stored.

A request that fails validation otherwise occupies its key for the whole TTL. Resubmitting the corrected payload with the same key returns `422 Unprocessable Entity`, because the request data no longer matches the stored fingerprint, even though the operation never happened. Disable `client_error` to free the key for those retries:

```php
'cache_statuses' => [
    'client_error' => false,
    'server_error' => false,
],
```

Categories you leave out stay enabled, so the map above still caches `1xx`, `2xx`, and `3xx` responses. Keep `redirection` enabled when you rely on the [request input](../basics/usage.md#request-input) flow, since a successful form submission usually answers with `302 Found`.

You may also set the categories per route or per controller with the `cacheStatuses` option:

```php
Route::post('/orders', StoreOrderController::class)->middleware(
    Idempotent::using(cacheStatuses: ['client_error' => false])
);
```

An unknown category name throws an `InvalidArgumentException` when options are resolved, so a typo fails fast instead of silently caching everything.

## Lock timeout

The `lock_timeout` option defines how long the in-flight atomic lock is held while a request is being processed:

```php
'lock_timeout' => env('IDEMPOTENCY_LOCK_TIMEOUT', 10),
```

Increase this value for endpoints with long processing times. If the lock expires before the endpoint finishes, a concurrent request with the same key may proceed.

Like `ttl`, the `lock_timeout` must resolve to a positive integer (`>= 1`). A value of `0` or lower throws an `InvalidArgumentException` when options are resolved.
