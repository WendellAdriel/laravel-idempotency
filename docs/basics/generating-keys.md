# Generating Keys

- [Introduction](#introduction)
- [Application-generated keys](#application-generated-keys)
- [Client-generated keys](#client-generated-keys)

## Introduction

An idempotency key identifies a retryable operation. The same operation should use the same key for every retry. A different operation should use a different key.

## Application-generated keys

If you need to generate an idempotency key in your application code, use the `Idempotency::key()` helper:

```php
use WendellAdriel\Idempotency\Idempotency;

$key = Idempotency::key();
```

This returns a random 64-character string.

## Client-generated keys

Most HTTP clients should generate and persist a key before sending the first request. If the request times out or the client is unsure whether the server completed the operation, it should retry with the same key and the same request payload.

Do not generate a new key for each retry attempt. A new key tells Laravel Idempotency that this is a different operation.
