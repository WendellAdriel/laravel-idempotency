<?php

declare(strict_types=1);

namespace WendellAdriel\Idempotency\Tests\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Cache\Lock as CacheLock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Override;

final class IndexLockTimeoutStore extends ArrayStore
{
    /** @var list<string> */
    public array $timedOutLocks = [];

    #[Override]
    public function lock($name, $seconds = 0, $owner = null): CacheLock
    {
        if (! is_string($name) || ! str_starts_with($name, 'idempotent-index-lock')) {
            return parent::lock($name, $seconds, $owner);
        }

        return new IndexLockTimeout($this, $name, $seconds, $owner);
    }
}

final class IndexLockTimeout extends Lock
{
    public function __construct(
        private readonly IndexLockTimeoutStore $store,
        string $name,
        int $seconds,
        ?string $owner = null,
    ) {
        parent::__construct($name, $seconds, $owner);
    }

    #[Override]
    public function acquire(): bool
    {
        return false;
    }

    #[Override]
    public function release(): bool
    {
        return true;
    }

    #[Override]
    public function forceRelease(): void {}

    #[Override]
    public function block($seconds, $callback = null): mixed
    {
        $this->store->timedOutLocks[] = $this->name;

        throw new LockTimeoutException();
    }

    #[Override]
    protected function getCurrentOwner(): ?string
    {
        return null;
    }
}
