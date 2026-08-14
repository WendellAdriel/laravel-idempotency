<?php

declare(strict_types=1);

namespace WendellAdriel\Idempotency\Tests\Support;

use Illuminate\Contracts\Cache\Store;

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
