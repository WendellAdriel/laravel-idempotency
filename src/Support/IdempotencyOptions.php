<?php

declare(strict_types=1);

namespace WendellAdriel\Idempotency\Support;

use InvalidArgumentException;
use WendellAdriel\Idempotency\Enums\IdempotencyScope;
use WendellAdriel\Idempotency\Enums\ResponseStatusClass;

final readonly class IdempotencyOptions
{
    /**
     * @param  array<string, bool>  $cacheStatuses
     */
    public function __construct(
        public int $ttl,
        public bool $required,
        public IdempotencyScope $scope,
        public string $header,
        public int $lockTimeout,
        public string $input = '_idempotency_key',
        public array $cacheStatuses = [],
    ) {}

    /**
     * @param  array<string, mixed>|string|null  $cacheStatuses
     */
    public static function resolve(
        null|int|string $ttl = null,
        null|bool|string $required = null,
        null|string|IdempotencyScope $scope = null,
        ?string $header = null,
        null|int|string $lockTimeout = null,
        null|array|string $cacheStatuses = null,
    ): self {
        return new self(
            ttl: self::resolveTtl($ttl),
            required: self::resolveRequired($required),
            scope: IdempotencyScope::fromConfig($scope),
            header: $header ?? config()->string('idempotency.header'),
            lockTimeout: self::resolveLockTimeout($lockTimeout),
            input: config()->string('idempotency.input'),
            cacheStatuses: self::resolveCacheStatuses($cacheStatuses),
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
            $this->serializeCacheStatuses(),
        ]);
    }

    private static function resolveTtl(null|int|string $ttl): int
    {
        return self::resolvePositiveInt($ttl, 'idempotency.ttl', 'ttl');
    }

    private static function resolveLockTimeout(null|int|string $lockTimeout): int
    {
        return self::resolvePositiveInt($lockTimeout, 'idempotency.lock_timeout', 'lock_timeout');
    }

    private static function resolvePositiveInt(null|int|string $value, string $configKey, string $option): int
    {
        $resolved = match (true) {
            is_int($value) => $value,
            $value !== null => (int) $value,
            default => config()->integer($configKey),
        };

        if ($resolved < 1) {
            throw new InvalidArgumentException("The {$option} must be a positive integer (>= 1).");
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

    /**
     * @param  array<string, mixed>|string|null  $cacheStatuses
     * @return array<string, bool>
     */
    private static function resolveCacheStatuses(null|array|string $cacheStatuses): array
    {
        // config() instead of config()->array() so that an absent key falls back
        // to the package default: an app holding a published config file from an
        // older version never runs mergeConfigFrom when its config is cached.
        $configured = $cacheStatuses ?? config('idempotency.cache_statuses', []);

        if (is_string($configured)) {
            return self::parseCacheStatusFlags($configured);
        }

        if (! is_array($configured)) {
            throw new InvalidArgumentException('The cache_statuses must be an array of response class flags.');
        }

        $overrides = [];

        foreach ($configured as $key => $enabled) {
            $class = ResponseStatusClass::tryFrom((string) $key)
                ?? throw new InvalidArgumentException(sprintf('Unsupported cache status class [%s].', $key));

            $overrides[$class->value] = is_bool($enabled)
                ? $enabled
                : filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
        }

        return self::normalizeCacheStatuses($overrides);
    }

    /**
     * @return array<string, bool>
     */
    private static function parseCacheStatusFlags(string $flags): array
    {
        $cases = ResponseStatusClass::cases();

        if (strlen($flags) !== count($cases) || preg_match('/^[01]+$/', $flags) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'The cache_statuses flags must be %d characters of 0 or 1.',
                count($cases),
            ));
        }

        $overrides = [];

        foreach ($cases as $index => $case) {
            $overrides[$case->value] = $flags[$index] === '1';
        }

        return $overrides;
    }

    /**
     * @param  array<string, bool>  $overrides
     * @return array<string, bool>
     */
    private static function normalizeCacheStatuses(array $overrides): array
    {
        $normalized = [];

        foreach (ResponseStatusClass::cases() as $case) {
            $normalized[$case->value] = $case->isEnabledIn($overrides);
        }

        return $normalized;
    }

    private function serializeCacheStatuses(): string
    {
        $flags = '';

        foreach (ResponseStatusClass::cases() as $case) {
            $flags .= $case->isEnabledIn($this->cacheStatuses) ? '1' : '0';
        }

        return $flags;
    }
}
