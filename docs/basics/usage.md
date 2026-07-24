# Usage

- [Route middleware](#route-middleware)
- [Request input](#request-input)
- [Custom middleware options](#custom-middleware-options)
- [Middleware alias](#middleware-alias)
- [Controller attribute](#controller-attribute)
- [Client behavior](#client-behavior)

## Route middleware

Attach the middleware to the routes that create or update data:

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

By default, the middleware only handles `POST`, `PUT`, and `PATCH` requests. Other HTTP methods pass through unchanged.

The middleware reads the key from the `Idempotency-Key` header or the `_idempotency_key` request input by default. If neither value contains a non-empty string and idempotency is required, the package returns `400 Bad Request`.

When the same key is sent again with the same request data, the original response is replayed and the response includes an `Idempotency-Replayed: true` header.

## Request input

Standard HTML forms cannot set custom request headers. You may include the idempotency key in a hidden input instead:

```blade
<form method="POST" action="/orders">
    @csrf
    @idempotency

    <input type="text" name="item">

    <button type="submit">Create order</button>
</form>
```

The `@idempotency` directive renders the configured hidden input with a new key from `Idempotency::key()`. To use a key generated elsewhere, pass it to the directive:

```blade
@idempotency($idempotencyKey)
```

Reuse the same value when retrying the same submission. Form fields and uploaded file metadata and contents must also match the original request. If they differ, the package returns `422 Unprocessable Entity` instead of replaying the stored response. See [generating keys](generating-keys.md) for more information about application-generated keys.

The configured header takes precedence when both sources contain a non-empty string. To use a different request input name, set `IDEMPOTENCY_INPUT` in your environment file:

```ini
IDEMPOTENCY_INPUT=_request_key
```

## Custom middleware options

Use the `Idempotent::using` helper when a route needs options that differ from the config file:

```php
use WendellAdriel\Idempotency\Enums\IdempotencyScope;
use WendellAdriel\Idempotency\Http\Middleware\Idempotent;

Route::post('/payments', ChargePaymentController::class)->middleware(
    Idempotent::using(
        ttl: 600,
        required: false,
        scope: IdempotencyScope::Ip,
        header: 'X-Idempotency-Key',
        lockTimeout: 30,
        cacheStatuses: ['client_error' => false],
    )
);
```

The route-level options are serialized into the middleware string and override the package configuration for that route only.

The `cacheStatuses` option controls which response categories are stored. Categories you leave out stay enabled, so the example above caches everything except `4xx` responses: a failed request leaves the key free and the client may retry with the same key instead of being blocked by a cached error for the rest of the TTL. See [cache statuses](../getting-started/configuration.md#cache-statuses) for the full list of categories.

## Middleware alias

Laravel Idempotency registers `idempotent` as a route middleware alias:

```php
Route::post('/orders', StoreOrderController::class)->middleware('idempotent');
```

The alias uses the configured `idempotency.header` and `idempotency.input` values. If your client sends `X-Idempotency-Key`, set `IDEMPOTENCY_HEADER=X-Idempotency-Key` or use `Idempotent::using(header: 'X-Idempotency-Key')` on the route.

## Controller attribute

If you prefer attributes, use the package's `#[Idempotent]` attribute. The attribute applies the same middleware and accepts the same `ttl`, `required`, `scope`, `header`, `lockTimeout`, and `cacheStatuses` options.

```php
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
namespace App\Http\Controllers;

use WendellAdriel\Idempotency\Attributes\Idempotent;
use WendellAdriel\Idempotency\Enums\IdempotencyScope;

#[Idempotent]
class PaymentController
{
    #[Idempotent(ttl: 600, scope: IdempotencyScope::Ip, header: 'X-Idempotency-Key', lockTimeout: 30, cacheStatuses: ['client_error' => false])]
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

Since the attribute extends Laravel's controller middleware attribute, you may limit it to selected methods using `only` and `except`:

```php
#[Idempotent(except: ['store'])]
class OrderController
{
    // ...
}
```

## Client behavior

Clients should reuse the same idempotency key in the configured header or request input when retrying the same submission. If a frontend receives or generates a fresh key after every successful request, each request is treated as a new operation and appears as a separate row in `idempotency:list`.

If the same key is reused with different request data, the package returns `422 Unprocessable Entity`. If a second matching request arrives while the first request is still being processed, the package returns `409 Conflict` with `Retry-After: 1`.

By default an error response is stored like any other, so a key that was used for a failed attempt cannot be reused with corrected data. Disable the `client_error` and `server_error` [cache statuses](../getting-started/configuration.md#cache-statuses) when clients keep a stable key per form or operation and should be able to retry after a failure.
