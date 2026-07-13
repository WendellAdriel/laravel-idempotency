<?php

declare(strict_types=1);

namespace WendellAdriel\Idempotency\Support;

use InvalidArgumentException;
use WendellAdriel\Idempotency\Enums\IdempotencyScope;

final readonly class IdempotencyOptions
{
    public function __construct(
        public int $ttl,
        public bool $required,
        public IdempotencyScope $scope,
        public string $header,
        public int $lockTimeout,
        public string $input = '_idempotency_key',
    ) {}

    public static function resolve(
        null|int|string $ttl = null,
        null|bool|string $required = null,
        null|string|IdempotencyScope $scope = null,
        ?string $header = null,
        null|int|string $lockTimeout = null,
    ): self {
        return new self(
            ttl: self::resolveTtl($ttl),
            required: self::resolveRequired($required),
            scope: IdempotencyScope::fromConfig($scope),
            header: $header ?? config()->string('idempotency.header'),
            lockTimeout: self::resolveLockTimeout($lockTimeout),
            input: config()->string('idempotency.input'),
        );
    }

    public function serialize(): string
    {
        return implode(',', [
            $this->ttl,
            $this->required ? '1' : '0',
            $this->scope->value,
            $this->header,
            $this->lockTimeout,
        ]);
    }

    private static function resolveTtl(null|int|string $ttl): int
    {
        if (is_int($ttl)) {
            return $ttl;
        }

        return $ttl !== null
            ? (int) $ttl
            : config()->integer('idempotency.ttl');
    }

    private static function resolveLockTimeout(null|int|string $lockTimeout): int
    {
        $resolved = is_int($lockTimeout)
            ? $lockTimeout
            : ($lockTimeout !== null
                ? (int) $lockTimeout
                : config()->integer('idempotency.lock_timeout'));

        if ($resolved < 1) {
            throw new InvalidArgumentException('The lock_timeout must be a positive integer (>= 1).');
        }

        return $resolved;
    }

    private static function resolveRequired(null|bool|string $required): bool
    {
        if (is_bool($required)) {
            return $required;
        }

        return $required !== null
            ? filter_var($required, FILTER_VALIDATE_BOOLEAN)
            : config()->boolean('idempotency.required');
    }
}
