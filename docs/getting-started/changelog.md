# Changelog

Here's a quick overview of the new features in the latest major versions of the package.

## 1.4.0

* Fixed request fingerprints for form and multipart submissions so changed fields, uploaded file metadata, and file contents return `422 Unprocessable Entity` instead of replaying a stored response.

## 1.3.0

* Added validation that the idempotency `ttl` resolves to a positive integer, throwing when misconfigured instead of silently disabling deduplication.

## 1.2.0

* Added configurable request-input idempotency keys for standard HTML form submissions.
* Added an `@idempotency` Blade directive that generates a hidden idempotency key input or accepts an existing key.

## 1.1.0

* Added configurable idempotency lock timeout support through the config file, route middleware options, and controller attribute options.

## 1.0.1

* Fixed registration of the `idempotent` route middleware alias.

## 1.0.0

* Initial release of HTTP idempotency middleware for Laravel applications.
