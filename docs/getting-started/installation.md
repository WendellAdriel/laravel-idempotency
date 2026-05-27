# Installation

- [Requirements](#requirements)
- [Installing Laravel Idempotency](#installing-laravel-idempotency)
- [Publishing package files](#publishing-package-files)
- [Next steps](#next-steps)

## Requirements

Laravel Idempotency requires PHP 8.3 or higher and supports Laravel 13.

## Installing Laravel Idempotency

You may install Laravel Idempotency into a Laravel application with Composer:

```shell
composer require wendelladriel/laravel-idempotency
```

Laravel discovers the package service provider automatically. The service provider registers the configuration file, `idempotent` route middleware alias, and maintenance commands used by the package.

## Publishing package files

You may publish all Laravel Idempotency resources with the package's umbrella tag:

```shell
php artisan vendor:publish --tag="idempotency"
```

This publishes the configuration file to `config/idempotency.php`.

If you only need the configuration file, you may use the config-specific tag:

```shell
php artisan vendor:publish --tag="idempotency-config"
```

## Next steps

After installation, review the [configuration options](configuration.md), choose a [scope](../basics/scopes.md), and attach the middleware or attribute to the endpoints that need safe retries.
