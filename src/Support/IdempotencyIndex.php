<?php

declare(strict_types=1);

namespace WendellAdriel\Idempotency\Support;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use WendellAdriel\Idempotency\Enums\IdempotencyScope;

final readonly class IdempotencyIndex
{
    public const string SCOPES_KEY = 'idempotent-index:scopes';

    public const string ENTRY_PREFIX = 'idempotent-index:';

    private const string LOCK_KEY = 'idempotent-index-lock';

    private const int LOCK_LEASE = 60;

    private const int LOCK_WAIT = 5;

    public function __construct(
        private Repository $cache,
    ) {}

    public function remember(IndexMember $member): void
    {
        $this->withLockOrSkip(function () use ($member): void {
            $entryKey = $this->entryKey($member->scope, $member->identifier);
            $scopeMember = $this->scopeMember($member->scope, $member->identifier);

            $entry = $this->loadEntry($entryKey);
            $entry[$member->storageKey] = $member;

            $ttl = $this->remainingTtl($entry);

            $this->cache->put($entryKey, $this->serializeEntry($entry), $ttl);

            $scopes = $this->loadScopes();
            if (! in_array($scopeMember, $scopes, true)) {
                $scopes[] = $scopeMember;
            }

            $this->cache->forever(self::SCOPES_KEY, $scopes);
        });
    }

    /**
     * @return list<IndexMember>
     */
    public function forMember(IdempotencyScope $scope, string $identifier): array
    {
        return $this->withLock(fn (): array => $this->forMemberWithoutLock($scope, $identifier));
    }

    /**
     * @return list<IndexMember>
     */
    public function all(): array
    {
        return $this->withLock(fn (): array => $this->allWithoutLock());
    }

    /**
     * @return list<string>
     */
    public function forget(IdempotencyScope $scope, string $identifier): array
    {
        return $this->withLock(fn (): array => $this->forgetWithoutLock($scope, $identifier));
    }

    public function forgetMember(IdempotencyScope $scope, string $identifier, string $storageKey): bool
    {
        return $this->withLock(fn (): bool => $this->forgetMemberWithoutLock($scope, $identifier, $storageKey));
    }

    /**
     * @return list<string>
     */
    public function forgetByClientKey(string $clientKey): array
    {
        return $this->withLock(fn (): array => $this->forgetByClientKeyWithoutLock($clientKey));
    }

    /**
     * @return list<string>
     */
    public function flush(): array
    {
        return $this->withLock(fn (): array => $this->flushWithoutLock());
    }

    /**
     * @return list<IndexMember>
     */
    private function forMemberWithoutLock(IdempotencyScope $scope, string $identifier): array
    {
        $entryKey = $this->entryKey($scope, $identifier);
        $entry = $this->loadEntry($entryKey);

        if ($entry === []) {
            return [];
        }

        $active = $this->pruneExpired($entry);

        if ($active === []) {
            $this->cache->forget($entryKey);
            $this->removeScope($this->scopeMember($scope, $identifier));

            return [];
        }

        if (count($active) !== count($entry)) {
            $this->cache->put($entryKey, $this->serializeEntry($active), $this->remainingTtl($active));
        }

        return array_values($active);
    }

    /**
     * @return list<IndexMember>
     */
    private function allWithoutLock(): array
    {
        $scopes = $this->loadScopes();
        $all = [];

        foreach ($scopes as $scopeMember) {
            $decoded = $this->splitScopeMember($scopeMember);

            if ($decoded === null) {
                $this->removeScope($scopeMember);

                continue;
            }

            [$scope, $identifier] = $decoded;
            $entryKey = $this->entryKey($scope, $identifier);
            $entry = $this->loadEntry($entryKey);

            if ($entry === []) {
                $this->cache->forget($entryKey);
                $this->removeScope($scopeMember);

                continue;
            }

            $active = $this->pruneExpired($entry);

            if ($active === []) {
                $this->cache->forget($entryKey);
                $this->removeScope($scopeMember);

                continue;
            }

            if (count($active) !== count($entry)) {
                $this->cache->put($entryKey, $this->serializeEntry($active), $this->remainingTtl($active));
            }

            foreach ($active as $member) {
                $all[] = $member;
            }
        }

        return $all;
    }

    /**
     * @return list<string>
     */
    private function forgetWithoutLock(IdempotencyScope $scope, string $identifier): array
    {
        $entryKey = $this->entryKey($scope, $identifier);
        $entry = $this->loadEntry($entryKey);

        if ($entry === []) {
            $this->removeScope($this->scopeMember($scope, $identifier));

            return [];
        }

        $storageKeys = array_keys($entry);
        $this->cache->forget($entryKey);
        $this->removeScope($this->scopeMember($scope, $identifier));

        return $storageKeys;
    }

    private function forgetMemberWithoutLock(IdempotencyScope $scope, string $identifier, string $storageKey): bool
    {
        $entryKey = $this->entryKey($scope, $identifier);
        $entry = $this->loadEntry($entryKey);

        if (! array_key_exists($storageKey, $entry)) {
            return false;
        }

        unset($entry[$storageKey]);

        if ($entry === []) {
            $this->cache->forget($entryKey);
            $this->removeScope($this->scopeMember($scope, $identifier));

            return true;
        }

        $this->cache->put($entryKey, $this->serializeEntry($entry), $this->remainingTtl($entry));

        return true;
    }

    /**
     * @return list<string>
     */
    private function forgetByClientKeyWithoutLock(string $clientKey): array
    {
        $removed = [];
        $scopes = $this->loadScopes();

        foreach ($scopes as $scopeMember) {
            $decoded = $this->splitScopeMember($scopeMember);

            if ($decoded === null) {
                $this->removeScope($scopeMember);

                continue;
            }

            [$scope, $identifier] = $decoded;
            $entryKey = $this->entryKey($scope, $identifier);
            $entry = $this->loadEntry($entryKey);

            if ($entry === []) {
                $this->removeScope($scopeMember);

                continue;
            }

            $mutated = false;
            foreach ($entry as $storageKey => $member) {
                if ($member->clientKey === $clientKey) {
                    $removed[] = $storageKey;
                    unset($entry[$storageKey]);
                    $mutated = true;
                }
            }

            if (! $mutated) {
                continue;
            }

            if ($entry === []) {
                $this->cache->forget($entryKey);
                $this->removeScope($scopeMember);

                continue;
            }

            $this->cache->put($entryKey, $this->serializeEntry($entry), $this->remainingTtl($entry));
        }

        return $removed;
    }

    /**
     * @return list<string>
     */
    private function flushWithoutLock(): array
    {
        $scopes = $this->loadScopes();
        $removed = [];

        foreach ($scopes as $scopeMember) {
            $decoded = $this->splitScopeMember($scopeMember);

            if ($decoded === null) {
                continue;
            }

            [$scope, $identifier] = $decoded;
            $entryKey = $this->entryKey($scope, $identifier);
            $entry = $this->loadEntry($entryKey);

            foreach (array_keys($entry) as $storageKey) {
                $removed[] = $storageKey;
            }

            $this->cache->forget($entryKey);
        }

        $this->cache->forget(self::SCOPES_KEY);

        return $removed;
    }

    private function entryKey(IdempotencyScope $scope, string $identifier): string
    {
        return self::ENTRY_PREFIX . $this->scopeMember($scope, $identifier);
    }

    private function scopeMember(IdempotencyScope $scope, string $identifier): string
    {
        return $scope === IdempotencyScope::Global
            ? IdempotencyScope::Global->value
            : sprintf('%s:%s', $scope->value, $identifier);
    }

    /**
     * @return array{0: IdempotencyScope, 1: string}|null
     */
    private function splitScopeMember(string $scopeMember): ?array
    {
        if ($scopeMember === IdempotencyScope::Global->value) {
            return [IdempotencyScope::Global, ''];
        }

        $pos = strpos($scopeMember, ':');
        if ($pos === false) {
            return null;
        }

        $scope = IdempotencyScope::tryFrom(substr($scopeMember, 0, $pos));
        if ($scope === null) {
            return null;
        }

        return [$scope, substr($scopeMember, $pos + 1)];
    }

    /**
     * @return list<string>
     */
    private function loadScopes(): array
    {
        $stored = $this->cache->get(self::SCOPES_KEY);

        if (! is_array($stored)) {
            return [];
        }

        return array_values(array_filter($stored, is_string(...)));
    }

    private function removeScope(string $scopeMember): void
    {
        $scopes = $this->loadScopes();
        $filtered = array_values(array_filter($scopes, static fn (string $member): bool => $member !== $scopeMember));

        if ($filtered === []) {
            $this->cache->forget(self::SCOPES_KEY);

            return;
        }

        if (count($filtered) === count($scopes)) {
            return;
        }

        $this->cache->forever(self::SCOPES_KEY, $filtered);
    }

    /**
     * @return array<string, IndexMember>
     */
    private function loadEntry(string $entryKey): array
    {
        $stored = $this->cache->get($entryKey);

        if (! is_array($stored)) {
            return [];
        }

        $entry = [];
        foreach ($stored as $storageKey => $data) {
            if (! is_string($storageKey)) {
                continue;
            }

            if (! is_array($data)) {
                continue;
            }

            $entry[$storageKey] = IndexMember::fromArray($data);
        }

        return $entry;
    }

    /**
     * @param  array<string, IndexMember>  $entry
     * @return array<string, array<string, mixed>>
     */
    private function serializeEntry(array $entry): array
    {
        $serialized = [];
        foreach ($entry as $storageKey => $member) {
            $serialized[$storageKey] = $member->toArray();
        }

        return $serialized;
    }

    /**
     * @param  array<string, IndexMember>  $entry
     * @return array<string, IndexMember>
     */
    private function pruneExpired(array $entry): array
    {
        $now = $this->now();
        $active = [];

        foreach ($entry as $storageKey => $member) {
            if ($member->expiresAt > $now) {
                $active[$storageKey] = $member;
            }
        }

        return $active;
    }

    /**
     * @param  array<string, IndexMember>  $entry
     */
    private function remainingTtl(array $entry): int
    {
        $max = 0;
        foreach ($entry as $member) {
            if ($member->expiresAt > $max) {
                $max = $member->expiresAt;
            }
        }

        return max(1, $max - $this->now());
    }

    private function now(): int
    {
        return Carbon::now()->getTimestamp();
    }

    /** @param Closure(): void $callback */
    private function withLockOrSkip(Closure $callback): void
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            $callback();

            return;
        }

        $lock = $store->lock(self::LOCK_KEY, self::LOCK_LEASE);

        try {
            $lock->block(self::LOCK_WAIT);
        } catch (LockTimeoutException) {
            return;
        }

        try {
            $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withLock(Closure $callback): mixed
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return $callback();
        }

        return $store->lock(self::LOCK_KEY, self::LOCK_LEASE)->block(self::LOCK_WAIT, $callback);
    }
}
