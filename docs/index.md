<div align="center">
    <img src="/banner.png" alt="Laravel Idempotency" style="width: 520px; max-width: 100%; height: auto; margin-bottom: 2rem;">
</div>

# Laravel Idempotency

- [Introduction](#introduction)
- [When to use Laravel Idempotency](#when-to-use-laravel-idempotency)
- [How Laravel Idempotency works](#how-laravel-idempotency-works)
- [Documentation](#documentation)

## Introduction

Laravel Idempotency helps you safely retry write-oriented HTTP requests without performing the same work twice. When a `POST`, `PUT`, or `PATCH` request is sent again with the same idempotency key and the same request data, the package replays the original response instead of executing your route again.

The package stores responses and acquires in-flight locks through Laravel's cache system. Use a cache driver that supports atomic locks in production.

## When to use Laravel Idempotency

Use Laravel Idempotency on endpoints where clients may retry a request after a timeout, network interruption, double-click, queue retry, or webhook redelivery. It is especially useful for checkout, payment, order creation, subscription changes, and other operations where executing the same request twice would create duplicate side effects.

Idempotency is not a replacement for unique database constraints or domain-level guards. It sits at the HTTP boundary and gives clients a safe retry contract for a specific request payload and idempotency key.

## How Laravel Idempotency works

Attach the middleware or attribute to routes that mutate state. The client sends an idempotency key in the configured header, which defaults to `Idempotency-Key`.

```php
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

The first request runs normally and stores the response. A later request with the same key, scope, route, method, and payload receives the stored response with an `Idempotency-Replayed: true` header.

If the same key is reused with different request data, the package returns `422 Unprocessable Entity`. If a matching request arrives while the first request is still running, the package returns `409 Conflict` with `Retry-After: 1`.

## Documentation

Read the docs in this order if you are adding Laravel Idempotency to an application for the first time:

- [Installation](getting-started/installation.md)
- [Configuration](getting-started/configuration.md)
- [Usage](basics/usage.md)
- [Scopes](basics/scopes.md)
- [Generating keys](basics/generating-keys.md)
- [Maintenance commands](operations/maintenance-commands.md)
