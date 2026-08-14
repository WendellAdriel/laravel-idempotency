<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Lock;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use WendellAdriel\Idempotency\Enums\IdempotencyScope;
use WendellAdriel\Idempotency\Http\Middleware\Idempotent;
use WendellAdriel\Idempotency\Support\IdempotencyIndex;
use WendellAdriel\Idempotency\Support\IndexMember;
use WendellAdriel\Idempotency\Tests\Support\IndexLockTimeoutStore;

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

final class NonLockingStore implements Store
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
}

final class CooperativeLockStore extends ArrayStore
{
    public bool $pauseOnEntryRead = false;

    /** @var array<string, int> */
    public array $pauseAfterLockAcquisition = [];

    /** @var array<string, string> */
    public array $lockOwners = [];

    /** @var list<string> */
    public array $lockEvents = [];

    /** @var list<int> */
    public array $leaseSeconds = [];

    /** @var list<int> */
    public array $waitSeconds = [];

    #[Override]
    public function get($key): mixed
    {
        $value = parent::get($key);

        if (
            $this->pauseOnEntryRead
            && is_string($key)
            && str_starts_with($key, IdempotencyIndex::ENTRY_PREFIX)
            && $key !== IdempotencyIndex::SCOPES_KEY
            && Fiber::getCurrent() instanceof Fiber
        ) {
            Fiber::suspend();
        }

        return $value;
    }

    #[Override]
    public function lock($name, $seconds = 0, $owner = null): Illuminate\Contracts\Cache\Lock
    {
        $this->leaseSeconds[] = $seconds;

        return new CooperativeLock($this, $name, $seconds, $owner);
    }

    public function acquired(string $name): void
    {
        $this->lockEvents[] = 'acquired:' . $name;

        if (($this->pauseAfterLockAcquisition[$name] ?? 0) <= 0 || ! Fiber::getCurrent() instanceof Fiber) {
            return;
        }

        $this->pauseAfterLockAcquisition[$name]--;

        Fiber::suspend();
    }

    public function released(string $name): void
    {
        $this->lockEvents[] = 'released:' . $name;
    }
}

final class CooperativeLock extends Lock
{
    public function __construct(
        private readonly CooperativeLockStore $store,
        string $name,
        int $seconds,
        ?string $owner = null,
    ) {
        parent::__construct($name, $seconds, $owner);
    }

    public function acquire(): bool
    {
        if (array_key_exists($this->name, $this->store->lockOwners)) {
            return false;
        }

        $this->store->lockOwners[$this->name] = $this->owner;
        $this->store->acquired($this->name);

        return true;
    }

    public function release(): bool
    {
        if (($this->store->lockOwners[$this->name] ?? null) !== $this->owner) {
            return false;
        }

        unset($this->store->lockOwners[$this->name]);
        $this->store->released($this->name);

        return true;
    }

    public function forceRelease(): void
    {
        unset($this->store->lockOwners[$this->name]);
    }

    #[Override]
    public function block($seconds, $callback = null): mixed
    {
        $this->store->waitSeconds[] = $seconds;

        while (! $this->acquire()) {
            Fiber::suspend();
        }

        if (! is_callable($callback)) {
            return true;
        }

        try {
            return $callback();
        } finally {
            $this->release();
        }
    }

    protected function getCurrentOwner(): ?string
    {
        return $this->store->lockOwners[$this->name] ?? null;
    }
}

test('remember stores a user member and registers the scope', function (): void {
    $this->index->remember(makeMember());

    $stored = $this->cache->get('idempotent-index:user:5');
    expect($stored)->toBeArray()
        ->and($stored)->toHaveKey('hash-1');

    $scopes = $this->cache->get('idempotent-index:scopes');
    expect($scopes)->toBeArray()
        ->and($scopes)->toHaveKey('user:5');
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
    expect($scopes)->toHaveKey('global');
});

test('remember called twice with the same storage key replaces the previous member', function (): void {
    $this->index->remember(makeMember(['status' => 200]));
    $this->index->remember(makeMember(['status' => 201]));

    $members = $this->index->forMember(IdempotencyScope::User, '5');
    expect($members)->toHaveCount(1)
        ->and($members[0]->status)->toBe(201);
});

test('remember uses separate lease and wait durations for the index lock', function (): void {
    $store = new CooperativeLockStore();
    $index = new IdempotencyIndex(new Repository($store));
    $index->remember(makeMember());

    expect($store->leaseSeconds)->toBe([60, 60])
        ->and($store->waitSeconds)->toBe([5, 5]);
});

test('remember skips the index update when the lock cannot be acquired in time', function (): void {
    $store = new IndexLockTimeoutStore();
    $existing = makeMember(['storageKey' => 'hash-existing']);
    $entryKey = 'idempotent-index:user:5';
    $store->put($entryKey, ['hash-existing' => $existing->toArray()], 3600);
    $store->put(IdempotencyIndex::SCOPES_KEY, ['user:5' => $existing->expiresAt], 3600);

    $index = new IdempotencyIndex(new Repository($store));

    $index->remember(makeMember(['storageKey' => 'hash-new']));

    expect($store)->toBeInstanceOf(LockProvider::class)
        ->and($store->get($entryKey))->toBe(['hash-existing' => $existing->toArray()])
        ->and($store->get(IdempotencyIndex::SCOPES_KEY))->toBe(['user:5' => $existing->expiresAt])
        ->and($store->timedOutLocks)->toBe(['idempotent-index-lock:scope:user:5'])
        ->and($store->locks)->toBe([]);
});

test('a non-locking cache store works by default', function (): void {
    $store = new NonLockingStore();

    expect($store)->not->toBeInstanceOf(LockProvider::class);

    $index = new IdempotencyIndex(new Repository($store));
    $index->remember(makeMember(['storageKey' => 'hash-a']));
    $index->remember(makeMember(['storageKey' => 'hash-b']));

    $members = $index->forMember(IdempotencyScope::User, '5');
    $keys = array_map(fn (IndexMember $member): string => $member->storageKey, $members);
    sort($keys);

    $this->app->instance('cache.store', new Repository($store));
    $this->app->forgetInstance(IdempotencyIndex::class);

    expect($keys)->toBe(['hash-a', 'hash-b'])
        ->and(Artisan::call('idempotency:list'))->toBe(0);
});

test('strict index locks reject a non-locking cache store for direct and maintenance use', function (): void {
    $store = new NonLockingStore();

    expect(fn (): IdempotencyIndex => new IdempotencyIndex(new Repository($store), strictLocks: true))
        ->toThrow(LogicException::class, 'The configured cache store does not support atomic locks.');

    config()->set('idempotency.strict_index_locks', true);
    $this->app->instance('cache.store', new Repository($store));
    $this->app->forgetInstance(IdempotencyIndex::class);

    expect(fn (): int => Artisan::call('idempotency:list'))
        ->toThrow(LogicException::class, 'The configured cache store does not support atomic locks.');
});

test('remember called with different storage keys under the same scope coexist', function (): void {
    $this->index->remember(makeMember(['storageKey' => 'hash-a']));
    $this->index->remember(makeMember(['storageKey' => 'hash-b']));

    $members = $this->index->forMember(IdempotencyScope::User, '5');
    $keys = array_map(fn (IndexMember $m): string => $m->storageKey, $members);
    sort($keys);

    expect($keys)->toBe(['hash-a', 'hash-b']);
});

test('concurrent remember calls preserve both index members', function (): void {
    $store = new CooperativeLockStore();
    $store->pauseOnEntryRead = true;
    $index = new IdempotencyIndex(new Repository($store));

    $first = new Fiber(fn () => $index->remember(makeMember(['storageKey' => 'hash-a'])));
    $second = new Fiber(fn () => $index->remember(makeMember(['storageKey' => 'hash-b'])));

    $first->start();
    $second->start();
    $first->resume();
    $second->resume();
    $second->resume();

    $members = $index->forMember(IdempotencyScope::User, '5');
    $keys = array_map(fn (IndexMember $member): string => $member->storageKey, $members);
    sort($keys);

    expect($first->isTerminated())->toBeTrue()
        ->and($second->isTerminated())->toBeTrue()
        ->and($keys)->toBe(['hash-a', 'hash-b']);
});

test('concurrent remembers for different scopes preserve both scope pointers', function (): void {
    $store = new CooperativeLockStore();
    $store->pauseOnEntryRead = true;
    $index = new IdempotencyIndex(new Repository($store));

    $first = new Fiber(fn () => $index->remember(makeMember([
        'storageKey' => 'hash-user',
        'identifier' => '5',
    ])));
    $second = new Fiber(fn () => $index->remember(makeMember([
        'storageKey' => 'hash-ip',
        'scope' => IdempotencyScope::Ip,
        'identifier' => '1.2.3.4',
    ])));

    $first->start();
    $second->start();
    $first->resume();
    $second->resume();
    $second->resume();

    $keys = array_map(fn (IndexMember $member): string => $member->storageKey, $index->all());
    sort($keys);

    expect($first->isTerminated())->toBeTrue()
        ->and($second->isTerminated())->toBeTrue()
        ->and($store->get(IdempotencyIndex::SCOPES_KEY))->toHaveKeys(['user:5', 'ip:1.2.3.4'])
        ->and($keys)->toBe(['hash-ip', 'hash-user']);
});

test('all releases the registry lock before acquiring a scope lock', function (): void {
    $store = new CooperativeLockStore();
    $index = new IdempotencyIndex(new Repository($store));
    $index->remember(makeMember(['storageKey' => 'hash-user']));
    $store->lockEvents = [];

    $index->all();

    $registryRelease = array_search('released:idempotent-index-lock', $store->lockEvents, true);
    $firstScopeLock = array_search('acquired:idempotent-index-lock:scope:user:5', $store->lockEvents, true);

    expect($registryRelease)->toBeInt()
        ->and($firstScopeLock)->toBeInt()
        ->and($registryRelease)->toBeLessThan($firstScopeLock)
        ->and($store->lockOwners)->toBe([]);
});

test('all processes scopes independently while a different scope is written', function (): void {
    $store = new CooperativeLockStore();
    $index = new IdempotencyIndex(new Repository($store));
    $index->remember(makeMember(['storageKey' => 'hash-5']));
    $index->remember(makeMember(['storageKey' => 'hash-6', 'identifier' => '6']));
    $store->pauseAfterLockAcquisition['idempotent-index-lock:scope:user:5'] = 1;

    $all = new Fiber(fn (): array => $index->all());
    $remember = new Fiber(fn () => $index->remember(makeMember([
        'storageKey' => 'hash-7',
        'identifier' => '7',
    ])));

    $all->start();
    $remember->start();

    expect($all->isSuspended())->toBeTrue()
        ->and($remember->isTerminated())->toBeTrue();

    $all->resume();

    expect($all->isTerminated())->toBeTrue()
        ->and($index->forMember(IdempotencyScope::User, '7'))->toHaveCount(1)
        ->and($store->lockOwners)->toBe([]);
});

test('all cleans expired and malformed scope pointers without removing a concurrent scope write', function (): void {
    Carbon::setTestNow('2026-01-01 00:00:00');

    try {
        $store = new CooperativeLockStore();
        $index = new IdempotencyIndex(new Repository($store));
        $now = Carbon::now()->getTimestamp();
        $expired = makeMember(['storageKey' => 'hash-expired', 'expiresAt' => $now - 1]);
        $store->forever('idempotent-index:user:5', ['hash-expired' => $expired->toArray()]);
        $store->forever(IdempotencyIndex::SCOPES_KEY, [
            'malformed' => $now + 3600,
            'user:5' => $now - 1,
        ]);
        $store->pauseAfterLockAcquisition['idempotent-index-lock:scope:user:5'] = 1;

        $all = new Fiber(fn (): array => $index->all());
        $remember = new Fiber(fn () => $index->remember(makeMember([
            'storageKey' => 'hash-new',
            'identifier' => '6',
        ])));

        $all->start();
        $remember->start();

        expect($all->isSuspended())->toBeTrue()
            ->and($remember->isTerminated())->toBeTrue();

        $all->resume();

        expect($all->isTerminated())->toBeTrue()
            ->and($store->get(IdempotencyIndex::SCOPES_KEY))->toHaveKey('user:6')
            ->and($store->get(IdempotencyIndex::SCOPES_KEY))->not->toHaveKeys(['malformed', 'user:5'])
            ->and($store->lockOwners)->toBe([]);
    } finally {
        Carbon::setTestNow();
    }
});

test('concurrent forget and remember calls do not restore a forgotten member', function (): void {
    $store = new CooperativeLockStore();
    $index = new IdempotencyIndex(new Repository($store));
    $index->remember(makeMember(['storageKey' => 'hash-old']));
    $store->pauseOnEntryRead = true;

    $forget = new Fiber(fn (): array => $index->forget(IdempotencyScope::User, '5'));
    $remember = new Fiber(fn () => $index->remember(makeMember(['storageKey' => 'hash-new'])));

    $forget->start();
    $remember->start();
    $forget->resume();
    $remember->resume();

    if (! $remember->isTerminated()) {
        $remember->resume();
    }

    $members = $index->forMember(IdempotencyScope::User, '5');
    $keys = array_map(fn (IndexMember $member): string => $member->storageKey, $members);

    expect($forget->isTerminated())->toBeTrue()
        ->and($remember->isTerminated())->toBeTrue()
        ->and($keys)->toBe(['hash-new']);
});

test('concurrent forget and remember calls preserve other scope pointers', function (): void {
    $store = new CooperativeLockStore();
    $index = new IdempotencyIndex(new Repository($store));
    $index->remember(makeMember(['storageKey' => 'hash-old']));
    $store->pauseOnEntryRead = true;

    $forget = new Fiber(fn (): array => $index->forget(IdempotencyScope::User, '5'));
    $remember = new Fiber(fn () => $index->remember(makeMember([
        'storageKey' => 'hash-new',
        'identifier' => '6',
    ])));

    $forget->start();
    $remember->start();
    $forget->resume();
    $remember->resume();
    $forget->resume();

    expect($forget->isTerminated())->toBeTrue()
        ->and($remember->isTerminated())->toBeTrue()
        ->and($store->get(IdempotencyIndex::SCOPES_KEY))->toBeArray()
        ->and($store->get(IdempotencyIndex::SCOPES_KEY))->toHaveKey('user:6')
        ->and($store->get(IdempotencyIndex::SCOPES_KEY))->not->toHaveKey('user:5');
});

test('forgetByClientKey processes scopes independently while a different scope is written', function (): void {
    $store = new CooperativeLockStore();
    $index = new IdempotencyIndex(new Repository($store));
    $index->remember(makeMember(['storageKey' => 'hash-5', 'clientKey' => 'target']));
    $index->remember(makeMember(['storageKey' => 'hash-6', 'identifier' => '6', 'clientKey' => 'target']));
    $store->pauseAfterLockAcquisition['idempotent-index-lock:scope:user:5'] = 1;

    $forget = new Fiber(fn (): array => $index->forgetByClientKey('target'));
    $remember = new Fiber(fn () => $index->remember(makeMember([
        'storageKey' => 'hash-7',
        'identifier' => '7',
    ])));

    $forget->start();
    $remember->start();

    expect($forget->isSuspended())->toBeTrue()
        ->and($remember->isTerminated())->toBeTrue();

    $forget->resume();

    expect($forget->isTerminated())->toBeTrue()
        ->and($forget->getReturn())->toBe(['hash-5', 'hash-6'])
        ->and($index->forMember(IdempotencyScope::User, '7'))->toHaveCount(1)
        ->and($store->lockOwners)->toBe([]);
});

test('flush preserves writes to processed and newly added scopes', function (): void {
    $store = new CooperativeLockStore();
    $index = new IdempotencyIndex(new Repository($store));
    $index->remember(makeMember(['storageKey' => 'hash-old-5']));
    $index->remember(makeMember(['storageKey' => 'hash-old-6', 'identifier' => '6']));
    $store->pauseAfterLockAcquisition['idempotent-index-lock:scope:user:6'] = 1;

    $flush = new Fiber(fn (): array => $index->flush());
    $flush->start();

    expect($flush->isSuspended())->toBeTrue();

    $index->remember(makeMember(['storageKey' => 'hash-new-5']));
    $index->remember(makeMember(['storageKey' => 'hash-new-7', 'identifier' => '7']));

    $flush->resume();
    $userFiveMembers = $index->forMember(IdempotencyScope::User, '5');
    $userSevenMembers = $index->forMember(IdempotencyScope::User, '7');

    expect($flush->isTerminated())->toBeTrue()
        ->and($flush->getReturn())->toBe(['hash-old-5', 'hash-old-6'])
        ->and($userFiveMembers)->toHaveCount(1)
        ->and($userFiveMembers[0]->storageKey)->toBe('hash-new-5')
        ->and($index->forMember(IdempotencyScope::User, '6'))->toBe([])
        ->and($userSevenMembers)->toHaveCount(1)
        ->and($userSevenMembers[0]->storageKey)->toBe('hash-new-7')
        ->and($store->lockOwners)->toBe([]);
});

test('forgetByClientKey preserves a matching write after its scope is processed', function (): void {
    $store = new CooperativeLockStore();
    $index = new IdempotencyIndex(new Repository($store));
    $index->remember(makeMember(['storageKey' => 'hash-old-5', 'clientKey' => 'target']));
    $index->remember(makeMember(['storageKey' => 'hash-old-6', 'identifier' => '6', 'clientKey' => 'target']));
    $store->pauseAfterLockAcquisition['idempotent-index-lock:scope:user:6'] = 1;

    $forget = new Fiber(fn (): array => $index->forgetByClientKey('target'));
    $forget->start();

    expect($forget->isSuspended())->toBeTrue();

    $index->remember(makeMember(['storageKey' => 'hash-new-5', 'clientKey' => 'target']));

    $forget->resume();
    $members = $index->forMember(IdempotencyScope::User, '5');

    expect($forget->isTerminated())->toBeTrue()
        ->and($forget->getReturn())->toBe(['hash-old-5', 'hash-old-6'])
        ->and($members)->toHaveCount(1)
        ->and($members[0]->storageKey)->toBe('hash-new-5')
        ->and($store->lockOwners)->toBe([]);
});

test('all pruning an expired scope preserves a concurrent scope write', function (): void {
    Carbon::setTestNow('2026-01-01 00:00:00');

    try {
        $store = new CooperativeLockStore();
        $index = new IdempotencyIndex(new Repository($store));
        $now = Carbon::now()->getTimestamp();
        $expired = makeMember([
            'storageKey' => 'hash-expired',
            'expiresAt' => $now - 1,
        ]);
        $store->forever('idempotent-index:user:5', ['hash-expired' => $expired->toArray()]);
        $store->forever(IdempotencyIndex::SCOPES_KEY, ['user:5' => $expired->expiresAt]);
        $store->pauseOnEntryRead = true;

        $all = new Fiber(fn (): array => $index->all());
        $remember = new Fiber(fn () => $index->remember(makeMember([
            'storageKey' => 'hash-new',
            'identifier' => '6',
        ])));

        $all->start();
        $remember->start();
        $all->resume();
        $remember->resume();
        $all->resume();

        $members = $index->all();

        expect($all->isTerminated())->toBeTrue()
            ->and($remember->isTerminated())->toBeTrue()
            ->and($members)->toHaveCount(1)
            ->and($members[0]->storageKey)->toBe('hash-new')
            ->and($store->get(IdempotencyIndex::SCOPES_KEY))->toBeArray()
            ->and($store->get(IdempotencyIndex::SCOPES_KEY))->toHaveKey('user:6')
            ->and($store->get(IdempotencyIndex::SCOPES_KEY))->not->toHaveKey('user:5');
    } finally {
        Carbon::setTestNow();
    }
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
    expect($scopes ?? [])->not->toHaveKey('user:5');
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

test('forgetMember shortens the scopes registry ttl to the remaining members', function (): void {
    Carbon::setTestNow('2026-01-01 00:00:00');
    $now = Carbon::now()->getTimestamp();

    $this->index->remember(makeMember([
        'storageKey' => 'hash-short',
        'expiresAt' => $now + 60,
    ]));
    $this->index->remember(makeMember([
        'storageKey' => 'hash-long',
        'expiresAt' => $now + 3600,
    ]));

    $this->index->forgetMember(IdempotencyScope::User, '5', 'hash-long');

    Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00')->addSeconds(120));

    expect($this->cache->get(IdempotencyIndex::SCOPES_KEY))->toBeNull();

    Carbon::setTestNow();
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

test('scopes registry outlives shorter entries while longer entries remain active', function (): void {
    Carbon::setTestNow('2026-01-01 00:00:00');
    $now = Carbon::now()->getTimestamp();

    $this->index->remember(makeMember([
        'storageKey' => 'hash-long',
        'identifier' => '5',
        'createdAt' => $now,
        'expiresAt' => $now + 3600,
    ]));

    $this->index->remember(makeMember([
        'storageKey' => 'hash-short',
        'identifier' => '6',
        'createdAt' => $now,
        'expiresAt' => $now + 60,
    ]));

    Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00')->addSeconds(120));

    $members = $this->index->all();

    expect($members)->toHaveCount(1)
        ->and($members[0]->storageKey)->toBe('hash-long')
        ->and($this->cache->get(IdempotencyIndex::SCOPES_KEY))
        ->toHaveKey('user:5')
        ->not->toHaveKey('user:6');

    Carbon::setTestNow();
});

test('remember prunes expired scope pointers during normal writes', function (): void {
    Carbon::setTestNow('2026-01-01 00:00:00');
    $now = Carbon::now()->getTimestamp();

    $this->index->remember(makeMember([
        'storageKey' => 'hash-expiring-5',
        'identifier' => '5',
        'expiresAt' => $now + 60,
    ]));
    $this->index->remember(makeMember([
        'storageKey' => 'hash-expiring-6',
        'identifier' => '6',
        'expiresAt' => $now + 60,
    ]));

    Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00')->addSeconds(120));
    $later = Carbon::now()->getTimestamp();

    $this->index->remember(makeMember([
        'storageKey' => 'hash-active',
        'identifier' => '7',
        'createdAt' => $later,
        'expiresAt' => $later + 3600,
    ]));

    expect($this->cache->get(IdempotencyIndex::SCOPES_KEY))->toBe([
        'user:7' => $later + 3600,
    ]);

    Carbon::setTestNow();
});

test('remember migrates a legacy scopes registry without hiding live entries', function (): void {
    Carbon::setTestNow('2026-01-01 00:00:00');
    $now = Carbon::now()->getTimestamp();

    $this->index->remember(makeMember([
        'storageKey' => 'hash-long',
        'identifier' => '5',
        'expiresAt' => $now + 3600,
    ]));
    $this->cache->put(IdempotencyIndex::SCOPES_KEY, ['user:5'], 3600);

    $this->index->remember(makeMember([
        'storageKey' => 'hash-short',
        'identifier' => '6',
        'expiresAt' => $now + 60,
    ]));

    expect($this->cache->get(IdempotencyIndex::SCOPES_KEY))->toBe([
        'user:5' => $now + 3600,
        'user:6' => $now + 60,
    ]);

    Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00')->addSeconds(120));

    $members = $this->index->all();

    expect($members)->toHaveCount(1)
        ->and($members[0]->storageKey)->toBe('hash-long');

    Carbon::setTestNow();
});

test('scopes registry expires after its last scope entry', function (): void {
    Carbon::setTestNow('2026-01-01 00:00:00');
    $now = Carbon::now()->getTimestamp();

    $this->index->remember(makeMember([
        'expiresAt' => $now + 60,
    ]));

    Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00')->addSeconds(120));

    expect($this->cache->get(IdempotencyIndex::SCOPES_KEY))->toBeNull();

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
    expect($scopes ?? [])->not->toHaveKey('user:5');

    Carbon::setTestNow();
});

test('all self-heals a stale scopes-set pointer that no longer has a backing entry', function (): void {
    $this->cache->forever('idempotent-index:scopes', ['user:999']);

    $result = $this->index->all();

    expect($result)->toBe([]);

    $scopes = $this->cache->get('idempotent-index:scopes');
    expect($scopes ?? [])->not->toHaveKey('user:999');
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
    expect($scopes)->toHaveKey('user:5');

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
