<div align="center">
    <img src="https://github.com/wendelladriel/laravel-idempotency/raw/main/art/banner.png" alt="Laravel Idempotency" height="300"/>
    <p>
        <h1>Laravel Idempotency</h1>
        HTTP idempotency middleware for Laravel applications
    </p>
</div>

<p align="center">
    <a href="https://packagist.org/packages/wendelladriel/laravel-idempotency"><img src="https://img.shields.io/packagist/v/wendelladriel/laravel-idempotency.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/wendelladriel/laravel-idempotency"><img src="https://img.shields.io/packagist/php-v/wendelladriel/laravel-idempotency.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/wendelladriel/laravel-idempotency"><img src="https://badge.laravel.cloud/badge/wendelladriel/laravel-idempotency?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/wendelladriel/laravel-idempotency/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/wendelladriel/laravel-idempotency/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/wendelladriel/laravel-idempotency"><img src="https://img.shields.io/packagist/dt/wendelladriel/laravel-idempotency.svg?style=flat-square" alt="Total Downloads"></a>
</p>

## Installation

You can install the package via composer:

```bash
composer require wendelladriel/laravel-idempotency
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="idempotency"
```

## Usage

Attach the middleware to routes that create or update data:

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

By default, the middleware reads the key from the `Idempotency-Key` header or the `_idempotency_key` request input. This allows standard HTML forms to include the key in a hidden input. When both values are present, the header takes precedence.

Use the `@idempotency` Blade directive to render the hidden input with a generated key:

```blade
<form method="POST" action="/orders">
    @csrf
    @idempotency

    <!-- ... -->
</form>
```

You may pass an existing key with `@idempotency($key)`.

When the same key is sent again with the same request data, the package replays the original response instead of executing your route again.

Customize a single route with `Idempotent::using`:

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
    )
);
```

You may also use the `idempotent` middleware alias:

```php
Route::post('/orders', StoreOrderController::class)->middleware('idempotent');
```

If you prefer attributes, use the package's `#[Idempotent]` attribute on a controller class or method:

```php
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

Generate a key in application code when needed:

```php
use WendellAdriel\Idempotency\Idempotency;

$key = Idempotency::key();
```

Inspect and prune cached entries with the included Artisan commands:

```bash
php artisan idempotency:list
php artisan idempotency:forget --key=checkout-1 --force
```

Access the full documentation [here](https://laravel-idempotency.wendelladriel.com).

## Changelog

Please see [CHANGELOG](https://laravel-idempotency.wendelladriel.com/getting-started/changelog) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Laravel Idempotency! You can read the contribution guide [here](CONTRIBUTING.md).

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Wendell Adriel](https://github.com/WendellAdriel)
- [All Contributors](../../contributors)

## License

Laravel Idempotency is open-sourced software licensed under the [MIT license](LICENSE).
