# Configuration

- [Introduction](#introduction)
- [Time to live](#time-to-live)
- [Required key](#required-key)
- [Scope](#scope)
- [Header name](#header-name)
- [Request input name](#request-input-name)
- [Lock timeout](#lock-timeout)

## Introduction

Laravel Idempotency stores its application-level options in `config/idempotency.php`. You may publish this file with the `idempotency-config` tag:

```shell
php artisan vendor:publish --tag="idempotency-config"
```

The default configuration is intentionally small. It controls stored response lifetime, key input behavior, scope resolution, and the in-flight lock timeout.

```php
return [
    'ttl' => env('IDEMPOTENCY_TTL', 3600),
    'required' => env('IDEMPOTENCY_REQUIRED', true),
    'scope' => env('IDEMPOTENCY_SCOPE', IdempotencyScope::User->value),
    'header' => env('IDEMPOTENCY_HEADER', 'Idempotency-Key'),
    'input' => env('IDEMPOTENCY_INPUT', '_idempotency_key'),
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

## Lock timeout

The `lock_timeout` option defines how long the in-flight atomic lock is held while a request is being processed:

```php
'lock_timeout' => env('IDEMPOTENCY_LOCK_TIMEOUT', 10),
```

Increase this value for endpoints with long processing times. If the lock expires before the endpoint finishes, a concurrent request with the same key may proceed.

Like `ttl`, the `lock_timeout` must resolve to a positive integer (`>= 1`). A value of `0` or lower throws an `InvalidArgumentException` when options are resolved.
