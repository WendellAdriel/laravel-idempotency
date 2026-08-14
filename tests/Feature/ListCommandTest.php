<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use WendellAdriel\Idempotency\Enums\IdempotencyScope;
use WendellAdriel\Idempotency\Http\Middleware\Idempotent;
use WendellAdriel\Idempotency\Support\IdempotencyIndex;
use WendellAdriel\Idempotency\Support\IndexMember;
use WendellAdriel\Idempotency\Tests\Support\NonLockingStore;

beforeEach(function (): void {
    Route::middleware('web')->group(function (): void {
        Route::post('/list/default', fn () => response()->json(['id' => 1]))->middleware(Idempotent::class);
        Route::post('/list/user', fn () => response()->json(['id' => 1]))->middleware(Idempotent::using(scope: IdempotencyScope::User));
        Route::post('/list/ip', fn () => response()->json(['id' => 1]))->middleware(Idempotent::using(scope: IdempotencyScope::Ip));
        Route::post('/list/global', fn () => response()->json(['id' => 1]))->middleware(Idempotent::using(scope: IdempotencyScope::Global));
    });
});

function seedListUserEntry(int $userId, string $clientKey): void
{
    test()->actingAs(new GenericUser(['id' => $userId]))
        ->postJson('/list/user', ['item' => 'widget'], ['Idempotency-Key' => $clientKey]);
}

test('with no entries prints a friendly message', function (): void {
    test()->artisan('idempotency:list')
        ->expectsOutputToContain('No idempotent entries cached.')
        ->assertExitCode(0);
});

test('lists entries from a non-locking cache store when used sequentially', function (): void {
    $cache = new Repository(new NonLockingStore());
    app()->instance('cache.store', $cache);
    app()->forgetInstance(IdempotencyIndex::class);

    app()->make(IdempotencyIndex::class)->remember(new IndexMember(
        storageKey: 'hash-non-locking',
        scope: IdempotencyScope::User,
        identifier: '1',
        clientKey: 'non-locking-key',
        route: '/list/user',
        method: 'POST',
        status: 200,
        createdAt: Carbon::now()->getTimestamp(),
        expiresAt: Carbon::now()->addHour()->getTimestamp(),
    ));

    test()->artisan('idempotency:list')
        ->expectsOutputToContain('non-locking-key')
        ->assertExitCode(0);
});

test('prints a row per active entry sorted by createdAt desc', function (): void {
    Carbon::setTestNow('2026-01-01 00:00:00');
    seedListUserEntry(1, 'key-1');

    Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00')->addSeconds(10));
    seedListUserEntry(2, 'key-2');

    test()->artisan('idempotency:list')
        ->expectsTable(
            ['Scope', 'Identifier', 'Idempotency Key', 'Route', 'Method', 'Status', 'Created At', 'Expires In'],
            [
                ['user', '2', 'key-2', '/list/user', 'POST', '200', '2026-01-01 00:00:10', '1h'],
                ['user', '1', 'key-1', '/list/user', 'POST', '200', '2026-01-01 00:00:00', '59m 50s'],
            ]
        )
        ->assertExitCode(0);

    Carbon::setTestNow();
});

test('filters by user scope and id', function (): void {
    seedListUserEntry(5, 'key-5');
    seedListUserEntry(6, 'key-6');

    test()->artisan('idempotency:list', ['--scope' => 'user', '--id' => '5'])
        ->expectsOutputToContain('key-5')
        ->doesntExpectOutputToContain('key-6')
        ->assertExitCode(0);
});

test('filters by user scope alone lists every user identifier', function (): void {
    seedListUserEntry(5, 'key-5');
    seedListUserEntry(6, 'key-6');

    test()->artisan('idempotency:list', ['--scope' => 'user'])
        ->expectsOutputToContain('key-5')
        ->expectsOutputToContain('key-6')
        ->assertExitCode(0);
});

test('filters by global scope without requiring an id', function (): void {
    test()->postJson('/list/global', ['item' => 'widget'], ['Idempotency-Key' => 'key-g']);
    seedListUserEntry(1, 'key-u');

    test()->artisan('idempotency:list', ['--scope' => 'global'])
        ->expectsOutputToContain('key-g')
        ->doesntExpectOutputToContain('key-u')
        ->assertExitCode(0);
});

test('--limit caps the result set', function (): void {
    seedListUserEntry(1, 'a');
    seedListUserEntry(2, 'b');
    seedListUserEntry(3, 'c');

    test()->artisan('idempotency:list', ['--limit' => 2])
        ->expectsOutputToContain('Showing 2')
        ->assertExitCode(0);
});

test('expires in renders as a human readable duration', function (): void {
    Carbon::setTestNow('2026-01-01 00:00:00');

    $index = app()->make(IdempotencyIndex::class);
    $index->remember(new IndexMember(
        storageKey: 'hash-1',
        scope: IdempotencyScope::User,
        identifier: '1',
        clientKey: 'key-1',
        route: '/list/user',
        method: 'POST',
        status: 200,
        createdAt: Carbon::now()->getTimestamp(),
        expiresAt: Carbon::now()->getTimestamp() + 3570,
    ));

    test()->artisan('idempotency:list')
        ->expectsOutputToContain('59m 30s')
        ->assertExitCode(0);

    Carbon::setTestNow();
});

test('expired members are pruned and not shown', function (): void {
    Carbon::setTestNow('2026-01-01 00:00:00');

    $index = app()->make(IdempotencyIndex::class);
    $index->remember(new IndexMember(
        storageKey: 'hash-expired',
        scope: IdempotencyScope::User,
        identifier: '1',
        clientKey: 'gone',
        route: '/list/user',
        method: 'POST',
        status: 200,
        createdAt: Carbon::now()->getTimestamp(),
        expiresAt: Carbon::now()->getTimestamp() + 10,
    ));

    Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00')->addMinutes(5));

    test()->artisan('idempotency:list')
        ->expectsOutputToContain('No idempotent entries cached.')
        ->assertExitCode(0);

    Carbon::setTestNow();
});

test('--scope=foo errors with a clear message', function (): void {
    test()->artisan('idempotency:list', ['--scope' => 'foo'])
        ->expectsOutputToContain('Unsupported scope [foo]. Use user, ip, or global.')
        ->assertExitCode(1);
});

test('--limit=0 prints a friendly message without crashing', function (): void {
    seedListUserEntry(1, 'a');

    test()->artisan('idempotency:list', ['--limit' => 0])
        ->expectsOutputToContain('Nothing to display.')
        ->assertExitCode(0);
});
