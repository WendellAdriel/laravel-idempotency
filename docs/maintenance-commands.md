# Maintenance Commands

- [Introduction](#introduction)
- [Listing cached entries](#listing-cached-entries)
- [Forgetting cached entries](#forgetting-cached-entries)

## Introduction

Laravel Idempotency ships two Artisan commands to inspect and clear cached idempotent entries. Both commands read from the same cache store the middleware uses, so the driver must support atomic locks in production.

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
