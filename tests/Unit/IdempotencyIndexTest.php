<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Lock;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use WendellAdriel\Idempotency\Enums\IdempotencyScope;
use WendellAdriel\Idempotency\Http\Middleware\Idempotent;
use WendellAdriel\Idempotency\Support\IdempotencyIndex;
use WendellAdriel\Idempotency\Support\IndexMember;

beforeEach(function (): void {
    $this->cache = $this->app->make(Cache::class);
    $this->index = new IdempotencyIndex($this->cache);
});

function makeMember(array $overrides = []): IndexMember
{
    $now = Carbon::now()->getTimestamp();

    return IndexMember::fromArray(array_merge([
        'storageKey' => 'hash-1',
        'scope' => IdempotencyScope::User->value,
        'identifier' => '5',
        'clientKey' => 'client-1',
        'route' => 'orders.store',
        'method' => 'POST',
        'status' => 200,
        'createdAt' => $now,
        'expiresAt' => $now + 3600,
    ], $overrides));
}

test('remember stores a user member and registers the scope', function (): void {
    $this->index->remember(makeMember());

    $stored = $this->cache->get('idempotent-index:user:5');
    expect($stored)->toBeArray()
        ->and($stored)->toHaveKey('hash-1');

    $scopes = $this->cache->get('idempotent-index:scopes');
    expect($scopes)->toBeArray()
        ->and($scopes)->toContain('user:5');
});

test('remember stores a global member with empty identifier and registers global scope', function (): void {
    $this->index->remember(makeMember([
        'storageKey' => 'hash-g',
        'scope' => IdempotencyScope::Global,
        'identifier' => '',
    ]));

    $stored = $this->cache->get('idempotent-index:global');
    expect($stored)->toBeArray()
        ->and($stored)->toHaveKey('hash-g');

    $scopes = $this->cache->get('idempotent-index:scopes');
    expect($scopes)->toContain('global');
});

test('remember called twice with the same storage key replaces the previous member', function (): void {
    $this->index->remember(makeMember(['status' => 200]));
    $this->index->remember(makeMember(['status' => 201]));

    $members = $this->index->forMember(IdempotencyScope::User, '5');
    expect($members)->toHaveCount(1)
        ->and($members[0]->status)->toBe(201);
});

test('remember serializes the entry write and the scopes write behind their own locks', function (): void {
    // Regression: two concurrent requests remembering different storage keys
    // under the same scope both used to read the entry before either had
    // written, so the second write silently discarded the first member. The
    // fix wraps both the per-entry read-modify-write and the shared scopes
    // registry read-modify-write in an atomic lock. This asserts the code
    // actually acquires those locks (mutual exclusion itself is Laravel's
    // Lock::block(), which is trusted framework behavior).
    $store = new class() extends ArrayStore
    {
        /** @var list<string> */
        public array $lockedKeys = [];

        public function lock($name, $seconds = 0, $owner = null): Illuminate\Contracts\Cache\Lock
        {
            $this->lockedKeys[] = $name;

            return parent::lock($name, $seconds, $owner);
        }
    };

    $index = new IdempotencyIndex(new Repository($store));
    $index->remember(makeMember());

    expect($store->lockedKeys)->toBe([
        'idempotent-index-lock:user:5',
        'idempotent-index-lock:scopes',
    ]);
});

test('remember falls back to an unsynchronized write when the lock cannot be acquired in time', function (): void {
    // Regression: a LockTimeoutException used to propagate straight out of
    // remember(), which is called after the response is already cached and
    // ready to return - turning a successful request into a 500 just because
    // the bookkeeping index lock lost a race under contention.
    $store = new class() extends ArrayStore
    {
        public function lock($name, $seconds = 0, $owner = null): Illuminate\Contracts\Cache\Lock
        {
            return new class($name, $seconds) extends Lock
            {
                public function acquire(): bool
                {
                    return true;
                }

                public function release(): bool
                {
                    return true;
                }

                public function forceRelease(): void {}

                protected function getCurrentOwner(): string
                {
                    return $this->owner;
                }

                public function block($seconds, $callback = null): mixed
                {
                    throw new LockTimeoutException();
                }
            };
        }
    };

    $index = new IdempotencyIndex(new Repository($store));

    $index->remember(makeMember());

    $members = $index->forMember(IdempotencyScope::User, '5');

    expect($members)->toHaveCount(1)
        ->and($members[0]->storageKey)->toBe('hash-1');
});

test('remember still works when the cache store does not support atomic locks', function (): void {
    $store = new class() implements Store
    {
        /** @var array<string, mixed> */
        private array $items = [];

        public function get($key): mixed
        {
            return $this->items[$key] ?? null;
        }

        public function many(array $keys): array
        {
            return array_map($this->get(...), array_combine($keys, $keys));
        }

        public function put($key, $value, $seconds): bool
        {
            $this->items[$key] = $value;

            return true;
        }

        public function putMany(array $values, $seconds): bool
        {
            foreach ($values as $key => $value) {
                $this->put($key, $value, $seconds);
            }

            return true;
        }

        public function increment($key, $value = 1): int
        {
            $this->items[$key] = (is_int($this->items[$key] ?? null) ? $this->items[$key] : 0) + $value;

            return $this->items[$key];
        }

        public function decrement($key, $value = 1): int
        {
            return $this->increment($key, -$value);
        }

        public function forever($key, $value): bool
        {
            return $this->put($key, $value, 0);
        }

        public function touch($key, $seconds): bool
        {
            return true;
        }

        public function forget($key): bool
        {
            unset($this->items[$key]);

            return true;
        }

        public function flush(): bool
        {
            $this->items = [];

            return true;
        }

        public function getPrefix(): string
        {
            return '';
        }
    };

    expect($store)->not->toBeInstanceOf(LockProvider::class);

    $index = new IdempotencyIndex(new Repository($store));
    $index->remember(makeMember(['storageKey' => 'hash-a']));
    $index->remember(makeMember(['storageKey' => 'hash-b']));

    $members = $index->forMember(IdempotencyScope::User, '5');
    $keys = array_map(fn (IndexMember $m): string => $m->storageKey, $members);
    sort($keys);

    expect($keys)->toBe(['hash-a', 'hash-b']);
});

test('remember called with different storage keys under the same scope coexist', function (): void {
    $this->index->remember(makeMember(['storageKey' => 'hash-a']));
    $this->index->remember(makeMember(['storageKey' => 'hash-b']));

    $members = $this->index->forMember(IdempotencyScope::User, '5');
    $keys = array_map(fn (IndexMember $m): string => $m->storageKey, $members);
    sort($keys);

    expect($keys)->toBe(['hash-a', 'hash-b']);
});

test('forMember returns only active members for the requested scope and identifier', function (): void {
    Carbon::setTestNow('2026-01-01 00:00:00');
    $now = Carbon::now()->getTimestamp();

    $this->index->remember(makeMember([
        'storageKey' => 'hash-active',
        'createdAt' => $now,
        'expiresAt' => $now + 3600,
    ]));
    $this->index->remember(makeMember([
        'storageKey' => 'hash-expired',
        'createdAt' => $now - 7200,
        'expiresAt' => $now - 10,
    ]));

    $members = $this->index->forMember(IdempotencyScope::User, '5');

    expect($members)->toHaveCount(1)
        ->and($members[0]->storageKey)->toBe('hash-active');

    Carbon::setTestNow();
});

test('all returns every active member across every scope', function (): void {
    Carbon::setTestNow('2026-01-01 00:00:00');
    $now = Carbon::now()->getTimestamp();

    $this->index->remember(makeMember([
        'storageKey' => 'hash-user',
        'scope' => IdempotencyScope::User,
        'identifier' => '5',
        'createdAt' => $now,
        'expiresAt' => $now + 60,
    ]));
    $this->index->remember(makeMember([
        'storageKey' => 'hash-ip',
        'scope' => IdempotencyScope::Ip,
        'identifier' => '1.2.3.4',
        'createdAt' => $now,
        'expiresAt' => $now + 60,
    ]));
    $this->index->remember(makeMember([
        'storageKey' => 'hash-global',
        'scope' => IdempotencyScope::Global,
        'identifier' => '',
        'createdAt' => $now,
        'expiresAt' => $now + 60,
    ]));

    $all = $this->index->all();

    $keys = array_map(fn (IndexMember $m): string => $m->storageKey, $all);
    sort($keys);

    expect($keys)->toBe(['hash-global', 'hash-ip', 'hash-user']);

    Carbon::setTestNow();
});

test('forget removes all members for a scope and returns their storage keys', function (): void {
    $this->index->remember(makeMember(['storageKey' => 'hash-a']));
    $this->index->remember(makeMember(['storageKey' => 'hash-b']));

    $removed = $this->index->forget(IdempotencyScope::User, '5');

    sort($removed);
    expect($removed)->toBe(['hash-a', 'hash-b'])
        ->and($this->cache->get('idempotent-index:user:5'))->toBeNull();

    $scopes = $this->cache->get('idempotent-index:scopes');
    expect($scopes ?? [])->not->toContain('user:5');
});

test('forgetMember removes exactly one member and leaves siblings intact', function (): void {
    $this->index->remember(makeMember(['storageKey' => 'hash-a']));
    $this->index->remember(makeMember(['storageKey' => 'hash-b']));

    $removed = $this->index->forgetMember(IdempotencyScope::User, '5', 'hash-a');

    expect($removed)->toBeTrue();

    $members = $this->index->forMember(IdempotencyScope::User, '5');
    expect($members)->toHaveCount(1)
        ->and($members[0]->storageKey)->toBe('hash-b');
});

test('forgetByClientKey removes every matching member across scopes', function (): void {
    $this->index->remember(makeMember([
        'storageKey' => 'hash-u5',
        'scope' => IdempotencyScope::User,
        'identifier' => '5',
        'clientKey' => 'abc',
    ]));
    $this->index->remember(makeMember([
        'storageKey' => 'hash-ip',
        'scope' => IdempotencyScope::Ip,
        'identifier' => '1.2.3.4',
        'clientKey' => 'abc',
    ]));
    $this->index->remember(makeMember([
        'storageKey' => 'hash-other',
        'scope' => IdempotencyScope::User,
        'identifier' => '5',
        'clientKey' => 'xyz',
    ]));

    $removed = $this->index->forgetByClientKey('abc');

    sort($removed);
    expect($removed)->toBe(['hash-ip', 'hash-u5']);

    $members = $this->index->forMember(IdempotencyScope::User, '5');
    expect($members)->toHaveCount(1)
        ->and($members[0]->storageKey)->toBe('hash-other');
});

test('flush removes every index entry and the scopes set', function (): void {
    $this->index->remember(makeMember(['storageKey' => 'hash-a']));
    $this->index->remember(makeMember([
        'storageKey' => 'hash-g',
        'scope' => IdempotencyScope::Global,
        'identifier' => '',
    ]));

    $removed = $this->index->flush();

    sort($removed);
    expect($removed)->toBe(['hash-a', 'hash-g'])
        ->and($this->cache->get('idempotent-index:user:5'))->toBeNull()
        ->and($this->cache->get('idempotent-index:global'))->toBeNull()
        ->and($this->cache->get('idempotent-index:scopes'))->toBeNull();
});

test('index entry ttl refreshes to the max expiresAt among its members', function (): void {
    Carbon::setTestNow('2026-01-01 00:00:00');
    $now = Carbon::now()->getTimestamp();

    $this->index->remember(makeMember([
        'storageKey' => 'hash-short',
        'createdAt' => $now,
        'expiresAt' => $now + 60,
    ]));
    $this->index->remember(makeMember([
        'storageKey' => 'hash-long',
        'createdAt' => $now,
        'expiresAt' => $now + 3600,
    ]));

    Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00')->addSeconds(120));

    $members = $this->index->forMember(IdempotencyScope::User, '5');
    expect($members)->toHaveCount(1)
        ->and($members[0]->storageKey)->toBe('hash-long');

    Carbon::setTestNow();
});

test('forMember returns an empty list when there is no index entry and writes nothing', function (): void {
    $result = $this->index->forMember(IdempotencyScope::User, '999');

    expect($result)->toBe([])
        ->and($this->cache->get('idempotent-index:user:999'))->toBeNull()
        ->and($this->cache->get('idempotent-index:scopes'))->toBeNull();
});

test('forMember prunes fully expired entries and cleans up the scopes set', function (): void {
    Carbon::setTestNow('2026-01-01 00:00:00');
    $now = Carbon::now()->getTimestamp();

    $this->index->remember(makeMember([
        'createdAt' => $now - 3600,
        'expiresAt' => $now - 1,
    ]));

    $members = $this->index->forMember(IdempotencyScope::User, '5');

    expect($members)->toBe([])
        ->and($this->cache->get('idempotent-index:user:5'))->toBeNull();

    $scopes = $this->cache->get('idempotent-index:scopes');
    expect($scopes ?? [])->not->toContain('user:5');

    Carbon::setTestNow();
});

test('all self-heals a stale scopes-set pointer that no longer has a backing entry', function (): void {
    $this->cache->forever('idempotent-index:scopes', ['user:999']);

    $result = $this->index->all();

    expect($result)->toBe([]);

    $scopes = $this->cache->get('idempotent-index:scopes');
    expect($scopes ?? [])->not->toContain('user:999');
});

test('forget global works without requiring a non-empty identifier', function (): void {
    $this->index->remember(makeMember([
        'storageKey' => 'hash-g',
        'scope' => IdempotencyScope::Global,
        'identifier' => '',
    ]));

    $removed = $this->index->forget(IdempotencyScope::Global, '');

    expect($removed)->toBe(['hash-g'])
        ->and($this->cache->get('idempotent-index:global'))->toBeNull();
});

test('remember refreshes the scopes set even when the entry pointer already exists', function (): void {
    Carbon::setTestNow('2026-01-01 00:00:00');
    $now = Carbon::now()->getTimestamp();

    $this->index->remember(makeMember([
        'storageKey' => 'hash-short',
        'createdAt' => $now,
        'expiresAt' => $now + 60,
    ]));

    $this->index->remember(makeMember([
        'storageKey' => 'hash-long',
        'createdAt' => $now,
        'expiresAt' => $now + 3600,
    ]));

    Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00')->addSeconds(120));

    $scopes = $this->cache->get('idempotent-index:scopes');
    expect($scopes)->toContain('user:5');

    Carbon::setTestNow();
});

test('forgetByClientKey with no matches returns an empty list', function (): void {
    $this->index->remember(makeMember(['clientKey' => 'real']));

    $removed = $this->index->forgetByClientKey('ghost');

    expect($removed)->toBe([]);

    $members = $this->index->forMember(IdempotencyScope::User, '5');
    expect($members)->toHaveCount(1);
});

test('service provider registers both commands with Artisan', function (): void {
    expect(array_keys(Artisan::all()))
        ->toContain('idempotency:forget', 'idempotency:list');
});

test('service provider registers the idempotent route middleware alias', function (): void {
    $aliases = $this->app->make(Router::class)->getMiddleware();

    expect($aliases)->toHaveKey('idempotent')
        ->and($aliases['idempotent'])->toBe(Idempotent::class);
});
