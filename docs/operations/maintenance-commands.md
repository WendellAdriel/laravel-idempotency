# Maintenance Commands

- [Introduction](#introduction)
- [Concurrent maintenance](#concurrent-maintenance)
- [Best-effort index bookkeeping](#best-effort-index-bookkeeping)
- [Listing cached entries](#listing-cached-entries)
- [Forgetting cached entries](#forgetting-cached-entries)

## Introduction

Laravel Idempotency ships two Artisan commands to inspect and clear cached idempotent entries. The HTTP middleware requires a cache store that supports atomic locks. In multi-server deployments, every application server must use the same shared cache backend. The `array` driver is only suitable for tests or single-process development. See Laravel's [atomic lock documentation](https://laravel.com/docs/cache#atomic-locks) for supported deployment options.

When `strict_index_locks` is disabled, the commands may use a non-locking store through the direct index fallback. Run only one direct index operation or maintenance command at a time with this fallback; concurrent use is unsupported. Enable `strict_index_locks` to fail fast instead. This fallback does not apply to middleware-managed HTTP requests.

## Concurrent maintenance

Commands that visit several scopes process one scope at a time instead of holding a single lock for the entire index. This lets requests continue writing entries for other scopes while a listing or bulk removal is running.

An entry written after a command has processed its scope may remain cached and can be listed or removed by a later command invocation. This boundary applies only to maintenance command results; it does not change the idempotent request-response guarantee.

## Best-effort index bookkeeping

The middleware stores a cacheable response before it updates the maintenance index. If an index lock times out during exceptional contention, it skips only that bookkeeping update. The original request still returns its successful response, and later requests with the same idempotency key replay the cached response normally.

Because the response is absent from the maintenance index, `idempotency:list` does not show it and `idempotency:forget` cannot remove it. The response expires normally according to its configured idempotency TTL. This is separate from the concurrent maintenance boundary above, where an entry is written after a command has processed its scope.

## Listing cached entries

Use `idempotency:list` to render a table of the currently cached entries:

```shell
php artisan idempotency:list
```

Example output:

```text
+--------+------------+------------------+----------------+--------+--------+---------------------+------------+
| Scope  | Identifier | Idempotency Key  | Route          | Method | Status | Created At          | Expires In |
+--------+------------+------------------+----------------+--------+--------+---------------------+------------+
| user   | 5          | checkout-1       | orders.store   | POST   | 201    | 2026-04-22 10:12:00 | 59m 30s    |
| ip     | 1.2.3.4    | guest-retry      | /webhooks/pay  | POST   | 200    | 2026-04-22 10:10:15 | 57m 45s    |
| global | -          | reconcile-job    | reports.sync   | POST   | 200    | 2026-04-22 10:05:02 | 52m 32s    |
+--------+------------+------------------+----------------+--------+--------+---------------------+------------+
```

The command accepts filters:

```shell
# every user-scoped row, any identifier
php artisan idempotency:list --scope=user

# a single user identity
php artisan idempotency:list --scope=user --id=5

# global entries
php artisan idempotency:list --scope=global

# cap the output
php artisan idempotency:list --limit=20
```

## Forgetting cached entries

Use `idempotency:forget` to remove cached entries. Destructive calls prompt for confirmation unless you pass `--force`.

```shell
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
