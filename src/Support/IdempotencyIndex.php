<?php

declare(strict_types=1);

namespace WendellAdriel\Idempotency\Support;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use LogicException;
use WendellAdriel\Idempotency\Enums\IdempotencyScope;

final readonly class IdempotencyIndex
{
    public const string SCOPES_KEY = 'idempotent-index:scopes';

    public const string ENTRY_PREFIX = 'idempotent-index:';

    private const string LOCK_KEY = 'idempotent-index-lock';

    private const int LOCK_LEASE = 60;

    private const int LOCK_WAIT = 5;

    private LockProvider $store;

    public function __construct(
        private Repository $cache,
    ) {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('The configured cache store does not support atomic locks.');
        }

        $this->store = $store;
    }

    public function remember(IndexMember $member): void
    {
        $this->withLockOrSkip(function () use ($member): void {
            $entryKey = $this->entryKey($member->scope, $member->identifier);
            $scopeMember = $this->scopeMember($member->scope, $member->identifier);

            $entry = $this->loadEntry($entryKey);
            $entry[$member->storageKey] = $member;

            $ttl = $this->remainingTtl($entry);

            $this->cache->put($entryKey, $this->serializeEntry($entry), $ttl);

            $this->updateScope($scopeMember, $entry);
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
            $this->updateScope($this->scopeMember($scope, $identifier), $active);
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

        foreach (array_keys($scopes) as $scopeMember) {
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
                $this->updateScope($scopeMember, $active);
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
        $this->updateScope($this->scopeMember($scope, $identifier), $entry);

        return true;
    }

    /**
     * @return list<string>
     */
    private function forgetByClientKeyWithoutLock(string $clientKey): array
    {
        $removed = [];
        $scopes = $this->loadScopes();

        foreach (array_keys($scopes) as $scopeMember) {
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
            $this->updateScope($scopeMember, $entry);
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

        foreach (array_keys($scopes) as $scopeMember) {
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
     * @return array<string, int>
     */
    private function loadScopes(): array
    {
        $stored = $this->cache->get(self::SCOPES_KEY);

        if (! is_array($stored)) {
            return [];
        }

        $scopes = [];
        $legacy = false;

        foreach ($stored as $scopeMember => $expiresAt) {
            if (is_string($scopeMember) && is_int($expiresAt)) {
                $scopes[$scopeMember] = $expiresAt;

                continue;
            }

            if (! is_string($expiresAt)) {
                continue;
            }

            $legacy = true;
            $entry = $this->loadEntry(self::ENTRY_PREFIX . $expiresAt);

            if ($entry !== []) {
                $scopes[$expiresAt] = $this->latestExpiration($entry);
            }
        }

        if ($legacy) {
            $this->storeScopes($scopes);
        }

        return $scopes;
    }

    /**
     * @param  array<string, IndexMember>  $entry
     */
    private function updateScope(string $scopeMember, array $entry): void
    {
        $scopes = $this->loadScopes();
        $scopes[$scopeMember] = $this->latestExpiration($entry);

        $this->storeScopes($scopes);
    }

    private function removeScope(string $scopeMember): void
    {
        $scopes = $this->loadScopes();

        if (! array_key_exists($scopeMember, $scopes)) {
            return;
        }

        unset($scopes[$scopeMember]);

        $this->storeScopes($scopes);
    }

    /**
     * @param  array<string, int>  $scopes
     */
    private function storeScopes(array $scopes): void
    {
        $now = $this->now();
        $active = array_filter($scopes, static fn (int $expiresAt): bool => $expiresAt > $now);

        if ($active === []) {
            $this->cache->forget(self::SCOPES_KEY);

            return;
        }

        $this->cache->put(self::SCOPES_KEY, $active, max($active) - $now);
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
        return max(1, $this->latestExpiration($entry) - $this->now());
    }

    /**
     * @param  array<string, IndexMember>  $entry
     */
    private function latestExpiration(array $entry): int
    {
        $latest = 0;

        foreach ($entry as $member) {
            if ($member->expiresAt > $latest) {
                $latest = $member->expiresAt;
            }
        }

        return $latest;
    }

    private function now(): int
    {
        return Carbon::now()->getTimestamp();
    }

    /** @param Closure(): void $callback */
    private function withLockOrSkip(Closure $callback): void
    {
        $lock = $this->store->lock(self::LOCK_KEY, self::LOCK_LEASE);

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
        return $this->store->lock(self::LOCK_KEY, self::LOCK_LEASE)->block(self::LOCK_WAIT, $callback);
    }
}
