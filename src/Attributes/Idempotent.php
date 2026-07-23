<?php

declare(strict_types=1);

namespace WendellAdriel\Idempotency\Attributes;

use Attribute;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use WendellAdriel\Idempotency\Enums\IdempotencyScope;
use WendellAdriel\Idempotency\Http\Middleware\Idempotent as IdempotentMiddleware;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Idempotent extends Middleware
{
    /**
     * @param  array<string>|null  $only
     * @param  array<string>|null  $except
     * @param  array<string, bool>|null  $cacheStatuses
     */
    public function __construct(
        ?int $ttl = null,
        ?bool $required = null,
        ?IdempotencyScope $scope = null,
        ?string $header = null,
        ?int $lockTimeout = null,
        ?array $only = null,
        ?array $except = null,
        ?array $cacheStatuses = null,
    ) {
        parent::__construct(
            IdempotentMiddleware::using(
                ttl: $ttl,
                required: $required,
                scope: $scope,
                header: $header,
                lockTimeout: $lockTimeout,
                cacheStatuses: $cacheStatuses,
            ),
            $only,
            $except,
        );
    }
}
