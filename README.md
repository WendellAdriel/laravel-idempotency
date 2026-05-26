<div align="center">
    <img src="https://github.com/WendellAdriel/laravel-idempotency/raw/main/art/logo.png" alt="Laravel Idempotency" height="300"/>
    <p>
        <h1>Laravel Idempotency</h1>
        HTTP idempotency middleware for Laravel applications
    </p>
</div>

<p align="center">
    <a href="https://packagist.org/packages/wendelladriel/laravel-idempotency"><img src="https://img.shields.io/packagist/v/wendelladriel/laravel-idempotency.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/wendelladriel/laravel-idempotency"><img src="https://img.shields.io/packagist/php-v/wendelladriel/laravel-idempotency.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/wendelladriel/laravel-idempotency"><img src="https://badge.laravel.cloud/badge/wendelladriel/laravel-idempotency" alt="Laravel versions"></a>
    <a href="https://github.com/WendellAdriel/laravel-idempotency/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/WendellAdriel/laravel-idempotency/tests.yml?branch=main&label=Tests"></a>
</p>

## Introduction

Laravel Idempotency helps you safely retry write-oriented HTTP requests without performing the same work twice. When a `POST`, `PUT`, or `PATCH` request is sent again with the same idempotency key and the same request data, the package replays the original response instead of executing your route again.

> [!NOTE]
> The middleware stores responses and acquires locks through Laravel's cache system. Use a cache driver that supports atomic locks.

## Features

- Segment idempotency keys by authenticated user, client IP, or globally.
- Apply idempotency through route middleware or an attribute.
- Replay cached responses for matching `POST`, `PUT`, and `PATCH` requests.
- Reject payload mismatches with `422 Unprocessable Entity`.
- Reject in-flight duplicate requests with `409 Conflict` and `Retry-After: 1`.
- Inspect and prune cached entries with the `idempotency:list` and `idempotency:forget` Artisan commands.
- Ships a [Laravel Boost](https://github.com/laravel/boost) AI skill so agents integrate the package correctly out of the box.

## Installation

```bash
composer require wendelladriel/laravel-idempotency
```

## Configuration

To publish the configuration file, run the following command:

```bash
php artisan vendor:publish --tag=idempotency
```

This will publish the package configuration to `config/idempotency.php`.

```php
return [
    'ttl' => env('IDEMPOTENCY_TTL', 3600),
    'lock_timeout' => env('IDEMPOTENCY_LOCK_TIMEOUT', 10),
    'required' => env('IDEMPOTENCY_REQUIRED', true),
    'scope' => env('IDEMPOTENCY_SCOPE', IdempotencyScope::User->value),
    'header' => env('IDEMPOTENCY_HEADER', 'Idempotency-Key'),
];
```

The available options are:

| Option         | Description                                                                                                                                         |
| -------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| `ttl`          | The number of seconds a stored response should remain available.                                                                                    |
| `lock_timeout` | The number of seconds the in-flight atomic lock is held while a request is being processed. Increase this for endpoints with long processing times. |
| `required`     | Determines whether the configured idempotency header is required.                                                                                   |
| `scope`        | Controls how keys are segmented. Supported values are `user`, `ip`, and `global`.                                                                   |
| `header`       | The request header the package should inspect for the client-provided idempotency key.                                                              |

## Usage

### Route middleware

To get started, attach the middleware to the routes that create or update data:

```php
<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use WendellAdriel\Idempotency\Http\Middleware\Idempotent;

Route::post('/orders', function (Request $request) {
    return response()->json([
        'id' => 1,
        'item' => $request->input('item'),
    ], 201);
})->middleware(Idempotent::class);
```

By default, the middleware expects an `Idempotency-Key` header. If the header is missing, the package returns a `400` response. When the same key is sent again with the same request data, the original response is replayed and the response includes an `Idempotency-Replayed: true` header.

If you need to customize the middleware, use the `Idempotent::using` helper when assigning it to the route:

```php
Route::post('/payments', ChargePaymentController::class)->middleware(
    Idempotent::using(
        ttl: 600,
        lockTimeout: 30,
        required: false,
        scope: \WendellAdriel\Idempotency\Enums\IdempotencyScope::Ip,
        header: 'X-Idempotency-Key',
    )
);
```

If you prefer middleware aliases, the package also registers `idempotent` as a route middleware alias.

```php
Route::post('/orders', StoreOrderController::class)->middleware('idempotent');
```

The `idempotent` alias uses the configured `idempotency.header` value. If your client sends `X-Idempotency-Key`, set `IDEMPOTENCY_HEADER=X-Idempotency-Key` or publish the config and update the `header` option. Alternatively, use `Idempotent::using(header: 'X-Idempotency-Key')` on the route.

Make sure the client reuses the same idempotency key when retrying the same submission. If a frontend receives or generates a fresh key after every successful request, each request is treated as a new operation and appears as a separate row in `idempotency:list`.

### Attribute

If you prefer attributes, you may use the package's `#[Idempotent]` attribute. The attribute applies the same middleware and accepts the same `ttl`, `lockTimeout`, `required`, `scope`, and `header` options.

```php
<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\Response;
use WendellAdriel\Idempotency\Attributes\Idempotent;

#[Idempotent]
class OrderController
{
    public function store(): Response
    {
        // ...
    }
}
```

You may also place the attribute on individual methods. Method-level attributes are merged with class-level attributes.

```php
<?php

namespace App\Http\Controllers;

use WendellAdriel\Idempotency\Attributes\Idempotent;
use WendellAdriel\Idempotency\Enums\IdempotencyScope;

#[Idempotent]
class PaymentController
{
    #[Idempotent(ttl: 600, lockTimeout: 30, scope: IdempotencyScope::Ip, header: 'X-Idempotency-Key')]
    public function store()
    {
        // ...
    }

    public function update()
    {
        // ...
    }
}
```

Since the attribute extends Laravel's controller middleware attribute, you may limit it to selected methods using `only` and `except`.

```php
#[Idempotent(except: ['store'])]
class OrderController
{
    // ...
}
```

### Scopes

The package supports three scopes:

| Scope | Behavior |
| --- | --- |
| `user` | Segments keys by authenticated user. Guest requests fall back to the client IP address. |
| `ip` | Segments keys by client IP address. |
| `global` | Reuses the same key across all users and IP addresses. |

If the same key is reused with different request data, the package returns a `422` response. If a second matching request arrives while the first request is still being processed, the package returns a `409` response with `Retry-After: 1`.

### Generating keys

If you need to generate an idempotency key in your application code, you may use the `Idempotency::key()` helper:

```php
use WendellAdriel\Idempotency\Idempotency;

$key = Idempotency::key();
```

This returns a random 64-character string.

## Maintenance commands

The package ships two Artisan commands to inspect and clear cached idempotent entries. Both commands read from the same cache store the middleware uses, so the driver must support atomic locks in production.

### Listing cached entries

Use `idempotency:list` to render a table of the currently cached entries:

```bash
php artisan idempotency:list
```

Example output:

```
+--------+------------+------------------+----------------+--------+--------+---------------------+------------+
| Scope  | Identifier | Idempotency Key  | Route          | Method | Status | Created At          | Expires In |
+--------+------------+------------------+----------------+--------+--------+---------------------+------------+
| user   | 5          | checkout-1       | orders.store   | POST   | 201    | 2026-04-22 10:12:00 | 59m 30s    |
| ip     | 1.2.3.4    | guest-retry      | /webhooks/pay  | POST   | 200    | 2026-04-22 10:10:15 | 57m 45s    |
| global | —          | reconcile-job    | reports.sync   | POST   | 200    | 2026-04-22 10:05:02 | 52m 32s    |
+--------+------------+------------------+----------------+--------+--------+---------------------+------------+
```

The command accepts filters:

```bash
# every user-scoped row, any identifier
php artisan idempotency:list --scope=user

# a single user identity
php artisan idempotency:list --scope=user --id=5

# global entries
php artisan idempotency:list --scope=global

# cap the output
php artisan idempotency:list --limit=20
```

### Forgetting cached entries

Use `idempotency:forget` to remove cached entries. Destructive calls prompt for confirmation unless you pass `--force`.

```bash
# remove everything (prompts for confirmation)
php artisan idempotency:forget --all
php artisan idempotency:forget --all --force

# remove a single user identity
php artisan idempotency:forget --scope=user --id=5 --force

# remove entries keyed to an IP address
php artisan idempotency:forget --scope=ip --id=1.2.3.4 --force

# remove global-scope entries
php artisan idempotency:forget --scope=global --force

# remove every entry that used a given client-provided key
php artisan idempotency:forget --key=checkout-1 --force
```

The `--all`, `--scope`, and `--key` options are mutually exclusive. When using `--scope=user` or `--scope=ip` you must also provide `--id`.

## Credits

- [Wendell Adriel](https://github.com/WendellAdriel)
- [All Contributors](../../contributors)

## Contributing

Check the **[Contributing Guide](CONTRIBUTING.md)**.
