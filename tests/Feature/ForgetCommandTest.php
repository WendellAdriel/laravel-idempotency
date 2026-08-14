<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use WendellAdriel\Idempotency\Enums\IdempotencyScope;
use WendellAdriel\Idempotency\Http\Middleware\Idempotent;
use WendellAdriel\Idempotency\Support\IdempotencyCache;
use WendellAdriel\Idempotency\Support\IdempotencyIndex;
use WendellAdriel\Idempotency\Support\IndexMember;
use WendellAdriel\Idempotency\Tests\Support\NonLockingStore;

beforeEach(function (): void {
    Route::middleware('web')->group(function (): void {
        Route::post('/forget/default', fn () => response()->json(['id' => 1]))->middleware(Idempotent::class);
        Route::post('/forget/user', fn () => response()->json(['id' => 1]))->middleware(Idempotent::using(scope: IdempotencyScope::User));
        Route::post('/forget/ip', fn () => response()->json(['id' => 1]))->middleware(Idempotent::using(scope: IdempotencyScope::Ip));
        Route::post('/forget/global', fn () => response()->json(['id' => 1]))->middleware(Idempotent::using(scope: IdempotencyScope::Global));
    });
});

function seedUserEntry(string $endpoint, int $userId, string $clientKey): void
{
    test()->actingAs(new GenericUser(['id' => $userId]))
        ->postJson($endpoint, ['item' => 'widget'], ['Idempotency-Key' => $clientKey]);
}

test('--all --force removes every index entry and response cache key', function (): void {
    seedUserEntry('/forget/user', 1, 'key-a');
    seedUserEntry('/forget/user', 2, 'key-b');
    test()->postJson('/forget/global', ['item' => 'widget'], ['Idempotency-Key' => 'key-g']);

    /** @var Cache $cache */
    $cache = app()->make(Cache::class);
    $index = app()->make(IdempotencyIndex::class);

    $members = $index->all();
    expect($members)->not->toBe([]);

    foreach ($members as $member) {
        expect($cache->get('idempotent-response:' . $member->storageKey))->not->toBeNull();
    }

    test()->artisan('idempotency:forget', ['--all' => true, '--force' => true])
        ->expectsOutputToContain('Removed 3 idempotent entries.')
        ->assertExitCode(0);

    expect($index->all())->toBe([]);

    foreach ($members as $member) {
        expect($cache->get('idempotent-response:' . $member->storageKey))->toBeNull();
    }

    expect($cache->get('idempotent-index:scopes'))->toBeNull();
});

test('forgets entries from a non-locking cache store when used sequentially', function (): void {
    $cache = new CacheRepository(new NonLockingStore());
    app()->instance('cache.store', $cache);
    app()->forgetInstance(IdempotencyCache::class);
    app()->forgetInstance(IdempotencyIndex::class);

    $index = app()->make(IdempotencyIndex::class);
    $index->remember(new IndexMember(
        storageKey: 'hash-non-locking',
        scope: IdempotencyScope::User,
        identifier: '1',
        clientKey: 'non-locking-key',
        route: '/forget/user',
        method: 'POST',
        status: 200,
        createdAt: Carbon::now()->getTimestamp(),
        expiresAt: Carbon::now()->addHour()->getTimestamp(),
    ));
    $cache->put('idempotent-response:hash-non-locking', ['status' => 200], 3600);

    test()->artisan('idempotency:forget', [
        '--scope' => 'user',
        '--id' => '1',
        '--force' => true,
    ])
        ->expectsOutputToContain('Removed 1 idempotent entries.')
        ->assertExitCode(0);

    expect($index->forMember(IdempotencyScope::User, '1'))->toBe([])
        ->and($cache->get('idempotent-response:hash-non-locking'))->toBeNull();
});

test('--scope=user --id filters to the matching user', function (): void {
    seedUserEntry('/forget/user', 5, 'key-u5');
    seedUserEntry('/forget/user', 6, 'key-u6');
    test()->postJson('/forget/ip', ['item' => 'widget'], ['Idempotency-Key' => 'key-ip']);

    test()->artisan('idempotency:forget', [
        '--scope' => 'user',
        '--id' => '5',
        '--force' => true,
    ])
        ->expectsOutputToContain('Removed 1 idempotent entries.')
        ->assertExitCode(0);

    $index = app()->make(IdempotencyIndex::class);

    expect($index->forMember(IdempotencyScope::User, '5'))->toBe([])
        ->and($index->forMember(IdempotencyScope::User, '6'))->toHaveCount(1)
        ->and($index->forMember(IdempotencyScope::Ip, '127.0.0.1'))->toHaveCount(1);
});

test('--scope=ip --id removes only that ip scope', function (): void {
    test()->postJson('/forget/ip', ['item' => 'widget'], ['Idempotency-Key' => 'key-ip']);

    test()->artisan('idempotency:forget', [
        '--scope' => 'ip',
        '--id' => '127.0.0.1',
        '--force' => true,
    ])
        ->expectsOutputToContain('Removed 1 idempotent entries.')
        ->assertExitCode(0);

    $index = app()->make(IdempotencyIndex::class);
    expect($index->forMember(IdempotencyScope::Ip, '127.0.0.1'))->toBe([]);
});

test('--scope=global removes only global entries', function (): void {
    test()->postJson('/forget/global', ['item' => 'widget'], ['Idempotency-Key' => 'key-g']);
    seedUserEntry('/forget/user', 1, 'key-u');

    test()->artisan('idempotency:forget', [
        '--scope' => 'global',
        '--force' => true,
    ])
        ->expectsOutputToContain('Removed 1 idempotent entries.')
        ->assertExitCode(0);

    $index = app()->make(IdempotencyIndex::class);
    expect($index->forMember(IdempotencyScope::Global, ''))->toBe([])
        ->and($index->forMember(IdempotencyScope::User, '1'))->toHaveCount(1);
});

test('--key removes every member matching the client key across scopes', function (): void {
    seedUserEntry('/forget/user', 1, 'shared-key');
    test()->postJson('/forget/ip', ['item' => 'widget'], ['Idempotency-Key' => 'shared-key']);
    seedUserEntry('/forget/user', 2, 'other-key');

    test()->artisan('idempotency:forget', [
        '--key' => 'shared-key',
        '--force' => true,
    ])
        ->expectsOutputToContain('Removed 2 idempotent entries.')
        ->assertExitCode(0);

    $index = app()->make(IdempotencyIndex::class);

    expect($index->forMember(IdempotencyScope::User, '1'))->toBe([])
        ->and($index->forMember(IdempotencyScope::Ip, '127.0.0.1'))->toBe([])
        ->and($index->forMember(IdempotencyScope::User, '2'))->toHaveCount(1);
});

test('running against an empty index still succeeds', function (): void {
    test()->artisan('idempotency:forget', ['--all' => true, '--force' => true])
        ->expectsOutputToContain('Removed 0 idempotent entries.')
        ->assertExitCode(0);
});

test('--all without --force asks for confirmation and can be declined', function (): void {
    seedUserEntry('/forget/user', 1, 'key-1');

    test()->artisan('idempotency:forget', ['--all' => true])
        ->expectsConfirmation('Are you sure you want to remove all idempotent entries?', 'no')
        ->expectsOutputToContain('Aborted.')
        ->assertExitCode(0);

    $index = app()->make(IdempotencyIndex::class);
    expect($index->all())->toHaveCount(1);
});

test('--scope=user without --id errors', function (): void {
    test()->artisan('idempotency:forget', ['--scope' => 'user', '--force' => true])
        ->expectsOutputToContain('The --id option is required when using --scope=user or --scope=ip.')
        ->assertExitCode(1);
});

test('--scope=foo errors with a clear message', function (): void {
    test()->artisan('idempotency:forget', ['--scope' => 'foo', '--force' => true])
        ->expectsOutputToContain('Unsupported scope [foo]. Use user, ip, or global.')
        ->assertExitCode(1);
});

test('passing both --all and --scope errors', function (): void {
    test()->artisan('idempotency:forget', ['--all' => true, '--scope' => 'global', '--force' => true])
        ->expectsOutputToContain('The --all, --scope, and --key options are mutually exclusive.')
        ->assertExitCode(1);
});

test('passing both --key and --scope errors', function (): void {
    test()->artisan('idempotency:forget', ['--key' => 'abc', '--scope' => 'global', '--force' => true])
        ->expectsOutputToContain('The --all, --scope, and --key options are mutually exclusive.')
        ->assertExitCode(1);
});

test('passing nothing at all errors', function (): void {
    test()->artisan('idempotency:forget')
        ->expectsOutputToContain('You must pass one of --all, --scope, or --key.')
        ->assertExitCode(1);
});
