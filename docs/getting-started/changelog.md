# Changelog

Here's a quick overview of the new features in the latest major versions of the package.

## 1.5.1

* Excluded fallback idempotency inputs, including nested paths, from JSON, form, and query fingerprints.
* Fixed empty form replay when unused fallback inputs are added or removed.
* Preserved exact retry replay for responses cached with the previous fingerprint format.

## 1.5.0

* Fixed the maintenance index expiring before entries with longer TTLs.
* Improved maintenance bookkeeping to coordinate concurrent index updates, process scopes independently, and keep successful responses replayable when an index lock times out.
* Added the `strict_index_locks` option to fail fast when direct index or maintenance command use targets a cache store without atomic locks. The default still supports sequential fallback use.
* Fixed streamed and binary file responses being replayed without their content by skipping caching when a response body cannot be captured.

## 1.4.0

* Added configurable cache statuses for stored responses through the config file, route middleware options, and controller attributes.

## 1.3.1

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
