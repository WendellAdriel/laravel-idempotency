<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use WendellAdriel\Idempotency\Enums\IdempotencyScope;
use WendellAdriel\Idempotency\Idempotency;
use WendellAdriel\Idempotency\Support\IdempotencyOptions;

test('it generates a 64 character idempotency key', function (): void {
    expect(Idempotency::key())
        ->toBeString()
        ->toHaveLength(64);
});

test('it generates a unique idempotency key for each call', function (): void {
    expect(Idempotency::key())->not->toBe(Idempotency::key());
});

test('it registers the expanded package config defaults', function (): void {
    expect(config()->integer('idempotency.ttl'))->toBe(3600)
        ->and(config()->integer('idempotency.lock_timeout'))->toBe(10)
        ->and(config()->boolean('idempotency.required'))->toBeTrue()
        ->and(config('idempotency.scope'))->toBe('user')
        ->and(config('idempotency.header'))->toBe('Idempotency-Key')
        ->and(config('idempotency.input'))->toBe('_idempotency_key')
        ->and(config('idempotency.cache_statuses'))->toBe([
            'informational' => true,
            'success' => true,
            'redirection' => true,
            'client_error' => true,
            'server_error' => true,
        ]);
});

test('it supports env-backed config overrides', function (): void {
    putenv('IDEMPOTENCY_TTL=120');
    putenv('IDEMPOTENCY_LOCK_TIMEOUT=45');
    putenv('IDEMPOTENCY_REQUIRED=false');
    putenv('IDEMPOTENCY_SCOPE=global');
    putenv('IDEMPOTENCY_HEADER=X-Idempotency-Key');
    putenv('IDEMPOTENCY_INPUT=_request_key');

    $config = require __DIR__ . '/../../config/idempotency.php';

    putenv('IDEMPOTENCY_TTL');
    putenv('IDEMPOTENCY_LOCK_TIMEOUT');
    putenv('IDEMPOTENCY_REQUIRED');
    putenv('IDEMPOTENCY_SCOPE');
    putenv('IDEMPOTENCY_HEADER');
    putenv('IDEMPOTENCY_INPUT');

    expect((int) $config['ttl'])->toBe(120)
        ->and((int) $config['lock_timeout'])->toBe(45)
        ->and((bool) $config['required'])->toBeFalse()
        ->and($config['scope'])->toBe('global')
        ->and($config['header'])->toBe('X-Idempotency-Key')
        ->and($config['input'])->toBe('_request_key');
});

test('it resolves the request input name from config', function (): void {
    config()->set('idempotency.input', '_request_key');

    expect(IdempotencyOptions::resolve()->input)->toBe('_request_key');
});

test('options accept the legacy positional constructor arguments', function (): void {
    $options = new IdempotencyOptions(
        3600,
        true,
        IdempotencyScope::User,
        'Idempotency-Key',
        10,
    );

    expect($options->input)->toBe('_idempotency_key')
        ->and($options->cacheStatuses)->toBe([])
        ->and($options->serialize())->toBe('3600,1,user,Idempotency-Key,10,11111');
});

test('options accept the legacy positional input argument', function (): void {
    // Regression: cacheStatuses must be appended after input, or the sixth
    // positional argument binds to the new parameter instead.
    $options = new IdempotencyOptions(
        3600,
        true,
        IdempotencyScope::User,
        'Idempotency-Key',
        10,
        '_request_key',
    );

    expect($options->input)->toBe('_request_key')
        ->and($options->cacheStatuses)->toBe([]);
});

test('a missing cache_statuses config key falls back to caching everything', function (): void {
    // Regression: an app upgrading with a published config file from an older
    // version and a cached config never runs mergeConfigFrom, so the key is
    // absent. Resolving must fall back to the previous behavior instead of
    // throwing or silently dropping responses.
    config()->set('idempotency', Arr::except(config('idempotency'), 'cache_statuses'));

    expect(config('idempotency.cache_statuses'))->toBeNull()
        ->and(IdempotencyOptions::resolve()->serialize())->toBe('3600,1,user,Idempotency-Key,10,11111');
});

test('a partial cache_statuses config map defaults the missing classes to enabled', function (): void {
    // mergeConfigFrom is shallow, so a published config file listing only some
    // of the classes replaces the package array wholesale.
    config()->set('idempotency.cache_statuses', ['client_error' => false]);

    expect(IdempotencyOptions::resolve()->cacheStatuses)->toBe([
        'informational' => true,
        'success' => true,
        'redirection' => true,
        'client_error' => false,
        'server_error' => true,
    ]);
});

test('string-form cache_statuses flags from the middleware string are resolved', function (): void {
    // Route middleware parameters arrive as strings (e.g. "idempotent:...,11100").
    expect(IdempotencyOptions::resolve(cacheStatuses: '11100')->cacheStatuses)->toBe([
        'informational' => true,
        'success' => true,
        'redirection' => true,
        'client_error' => false,
        'server_error' => false,
    ]);
});

test('malformed cache_statuses flags are rejected', function (): void {
    foreach (['1110', '111112', 'abcde', ''] as $flags) {
        expect(fn (): IdempotencyOptions => IdempotencyOptions::resolve(cacheStatuses: $flags))
            ->toThrow(InvalidArgumentException::class, 'The cache_statuses flags must be 5 characters of 0 or 1.');
    }
});

test('an unknown cache status category is rejected', function (): void {
    expect(fn (): IdempotencyOptions => IdempotencyOptions::resolve(cacheStatuses: ['sucess' => false]))
        ->toThrow(InvalidArgumentException::class, 'Unsupported cache status category [sucess].');
});

test('a non array cache_statuses config value is rejected', function (): void {
    config()->set('idempotency.cache_statuses', 42);

    expect(fn (): IdempotencyOptions => IdempotencyOptions::resolve())
        ->toThrow(InvalidArgumentException::class, 'The cache_statuses must be an array of response category flags.');
});

test('truthy cache_statuses values from a published config are cast to booleans', function (): void {
    config()->set('idempotency.cache_statuses', ['client_error' => 'false', 'server_error' => 0]);

    expect(IdempotencyOptions::resolve()->cacheStatuses)->toBe([
        'informational' => true,
        'success' => true,
        'redirection' => true,
        'client_error' => false,
        'server_error' => false,
    ]);
});

test('lock_timeout of zero is rejected', function (): void {
    expect(fn (): IdempotencyOptions => IdempotencyOptions::resolve(lockTimeout: 0))
        ->toThrow(InvalidArgumentException::class, 'The lock_timeout must be a positive integer (>= 1).');
});

test('negative lock_timeout is rejected', function (): void {
    expect(fn (): IdempotencyOptions => IdempotencyOptions::resolve(lockTimeout: -5))
        ->toThrow(InvalidArgumentException::class, 'The lock_timeout must be a positive integer (>= 1).');
});

test('lock_timeout of zero from config is rejected', function (): void {
    config()->set('idempotency.lock_timeout', 0);

    expect(fn (): IdempotencyOptions => IdempotencyOptions::resolve())
        ->toThrow(InvalidArgumentException::class, 'The lock_timeout must be a positive integer (>= 1).');
});

test('negative lock_timeout from config is rejected', function (): void {
    config()->set('idempotency.lock_timeout', -3);

    expect(fn (): IdempotencyOptions => IdempotencyOptions::resolve())
        ->toThrow(InvalidArgumentException::class, 'The lock_timeout must be a positive integer (>= 1).');
});

test('lock_timeout of one is the minimum accepted value', function (): void {
    $options = IdempotencyOptions::resolve(lockTimeout: 1);

    expect($options->lockTimeout)->toBe(1);
});

test('string-form negative lock_timeout is rejected', function (): void {
    // Route middleware parameters arrive as strings (e.g. "idempotent:...,-1").
    expect(fn (): IdempotencyOptions => IdempotencyOptions::resolve(lockTimeout: '-1'))
        ->toThrow(InvalidArgumentException::class, 'The lock_timeout must be a positive integer (>= 1).');
});
