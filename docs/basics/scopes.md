# Scopes

- [Introduction](#introduction)
- [User scope](#user-scope)
- [IP scope](#ip-scope)
- [Global scope](#global-scope)
- [Choosing a scope](#choosing-a-scope)

## Introduction

Scopes control how client-provided idempotency keys are segmented. The same key may be valid for different users or clients depending on the configured scope.

Laravel Idempotency supports three scopes:

| Scope | Behavior |
| --- | --- |
| `user` | Segments keys by authenticated user. Guest requests fall back to the client IP address. |
| `ip` | Segments keys by client IP address. |
| `global` | Reuses the same key across all users and IP addresses. |

## User scope

The `user` scope is the default. It segments keys by the authenticated user ID, which allows two different users to send the same idempotency key without colliding.

Guest requests fall back to the client IP address because there is no authenticated user ID to include in the scope.

## IP scope

The `ip` scope segments keys by the request IP address. This is useful for endpoints that do not require authentication but still need to prevent duplicate submissions from the same client.

## Global scope

The `global` scope shares keys across every user and IP address. Use this only when a key should represent one operation globally, such as a webhook event ID from a trusted provider.

## Choosing a scope

Use `user` for authenticated application endpoints, `ip` for guest-facing forms, and `global` for provider-generated identifiers that are globally unique.

You may set the default scope in the config file or override it for a single route:

```php
use WendellAdriel\Idempotency\Enums\IdempotencyScope;
use WendellAdriel\Idempotency\Http\Middleware\Idempotent;

Route::post('/webhooks/payment', HandlePaymentWebhookController::class)->middleware(
    Idempotent::using(scope: IdempotencyScope::Global)
);
```
