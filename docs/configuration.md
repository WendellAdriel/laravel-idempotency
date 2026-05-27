# Configuration

- [Introduction](#introduction)
- [Time to live](#time-to-live)
- [Required header](#required-header)
- [Scope](#scope)
- [Header name](#header-name)
- [Lock timeout](#lock-timeout)

## Introduction

Laravel Idempotency stores its application-level options in `config/idempotency.php`. You may publish this file with the `idempotency-config` tag:

```shell
php artisan vendor:publish --tag="idempotency-config"
```

The default configuration is intentionally small. It controls stored response lifetime, header behavior, scope resolution, and the in-flight lock timeout.

```php
return [
    'ttl' => env('IDEMPOTENCY_TTL', 3600),
    'required' => env('IDEMPOTENCY_REQUIRED', true),
    'scope' => env('IDEMPOTENCY_SCOPE', IdempotencyScope::User->value),
    'header' => env('IDEMPOTENCY_HEADER', 'Idempotency-Key'),
    'lock_timeout' => env('IDEMPOTENCY_LOCK_TIMEOUT', 10),
];
```

## Time to live

The `ttl` option defines how long a stored response remains available, in seconds:

```php
'ttl' => env('IDEMPOTENCY_TTL', 3600),
```

Use a value long enough for the retry window your clients need. After the TTL expires, the same idempotency key is treated as a new request.

## Required header

The `required` option determines whether protected requests must include the configured idempotency header:

```php
'required' => env('IDEMPOTENCY_REQUIRED', true),
```

When this is `true`, a missing header returns `400 Bad Request`. When this is `false`, requests without the header pass through and are not stored.

## Scope

The `scope` option controls how client-provided keys are segmented:

```php
'scope' => env('IDEMPOTENCY_SCOPE', IdempotencyScope::User->value),
```

Supported values are `user`, `ip`, and `global`. See [scopes](scopes.md) for the behavior of each option.

## Header name

The `header` option defines which request header contains the client-provided idempotency key:

```php
'header' => env('IDEMPOTENCY_HEADER', 'Idempotency-Key'),
```

If your client sends `X-Idempotency-Key`, set `IDEMPOTENCY_HEADER=X-Idempotency-Key` or override the header for a single route.

## Lock timeout

The `lock_timeout` option defines how long the in-flight atomic lock is held while a request is being processed:

```php
'lock_timeout' => env('IDEMPOTENCY_LOCK_TIMEOUT', 10),
```

Increase this value for endpoints with long processing times. If the lock expires before the endpoint finishes, a concurrent request with the same key may proceed.
