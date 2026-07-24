<?php

declare(strict_types=1);

namespace WendellAdriel\Idempotency\Http\Middleware;

use Closure;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use WendellAdriel\Idempotency\Enums\IdempotencyScope;
use WendellAdriel\Idempotency\Enums\ResponseCategory;
use WendellAdriel\Idempotency\Support\IdempotencyCache;
use WendellAdriel\Idempotency\Support\IdempotencyIndex;
use WendellAdriel\Idempotency\Support\IdempotencyOptions;
use WendellAdriel\Idempotency\Support\IndexMember;
use WendellAdriel\Idempotency\Support\RequestFingerprint;
use WendellAdriel\Idempotency\Support\ScopeResolver;
use WendellAdriel\Idempotency\Support\StoredResponse;

final readonly class Idempotent
{
    public function __construct(
        private Repository $cache,
        private IdempotencyCache $idempotencyCache,
        private IdempotencyIndex $idempotencyIndex,
        private ScopeResolver $scopeResolver,
        private RequestFingerprint $fingerprint,
    ) {}

    /**
     * @param  array<string, bool>|null  $cacheStatuses
     */
    public static function using(
        ?int $ttl = null,
        ?bool $required = null,
        ?IdempotencyScope $scope = null,
        ?string $header = null,
        ?int $lockTimeout = null,
        ?array $cacheStatuses = null,
    ): string {
        return self::class . ':' . IdempotencyOptions::resolve(
            ttl: $ttl,
            required: $required,
            scope: $scope,
            header: $header,
            lockTimeout: $lockTimeout,
            cacheStatuses: $cacheStatuses,
        )->serialize();
    }

    /**
     * @param  array<string, bool>|string|null  $cacheStatuses
     */
    public function handle(
        Request $request,
        Closure $next,
        null|int|string $ttl = null,
        null|bool|string $required = null,
        null|string|IdempotencyScope $scope = null,
        ?string $header = null,
        null|int|string $lockTimeout = null,
        null|array|string $cacheStatuses = null,
    ): SymfonyResponse {
        if (! $this->isIdempotentMethod($request)) {
            return $next($request);
        }

        $options = IdempotencyOptions::resolve(
            ttl: $ttl,
            required: $required,
            scope: $scope,
            header: $header,
            lockTimeout: $lockTimeout,
            cacheStatuses: $cacheStatuses,
        );
        $clientKey = $request->header($options->header);

        if (! is_string($clientKey) || $clientKey === '') {
            $clientKey = $request->input($options->input);
        }

        if (! is_string($clientKey) || $clientKey === '') {
            if ($options->required) {
                throw new HttpException(Response::HTTP_BAD_REQUEST, sprintf('Missing required header: %s', $options->header));
            }

            return $next($request);
        }

        [$resolvedScope, $identifier] = $this->scopeResolver->describe($request, $options->scope);
        $scopePrefix = $resolvedScope === IdempotencyScope::Global
            ? IdempotencyScope::Global->value
            : sprintf('%s:%s', $resolvedScope->value, $identifier);
        $storageKey = $this->fingerprint->storageKey($request, $scopePrefix, $options->header, $clientKey);
        $fingerprint = $this->fingerprint->fingerprint($request);
        $stored = $this->idempotencyCache->get($storageKey);

        if ($stored instanceof StoredResponse) {
            if ($stored->fingerprint !== $fingerprint) {
                throw new HttpException(Response::HTTP_UNPROCESSABLE_ENTITY, 'Idempotency key already used with different request parameters.');
            }

            return $stored->toResponse();
        }

        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new HttpException(Response::HTTP_INTERNAL_SERVER_ERROR, 'The configured cache store does not support atomic locks.');
        }

        $lock = $store->lock($this->idempotencyCache->lockKey($storageKey), $options->lockTimeout);

        if (! $lock->get()) {
            throw new HttpException(Response::HTTP_CONFLICT, 'A request with this idempotency key is currently being processed.', null, ['Retry-After' => '1']);
        }

        $request->attributes->set('idempotent', true);
        $request->attributes->set('idempotency-key', $clientKey);

        try {
            /** @var SymfonyResponse $response */
            $response = $next($request);

            if (! $this->shouldCache($response, $options)) {
                return $response;
            }

            $this->idempotencyCache->put(
                $storageKey,
                $this->idempotencyCache->serializeResponse($response, $fingerprint),
                $options->ttl,
            );

            $now = Carbon::now()->getTimestamp();
            $this->idempotencyIndex->remember(new IndexMember(
                storageKey: $storageKey,
                scope: $resolvedScope,
                identifier: $identifier,
                clientKey: $clientKey,
                route: $this->fingerprint->routeIdentity($request),
                method: strtoupper($request->method()),
                status: $response->getStatusCode(),
                createdAt: $now,
                expiresAt: $now + $options->ttl,
            ));

            return $response;
        } finally {
            $lock->release();
        }
    }

    private function shouldCache(SymfonyResponse $response, IdempotencyOptions $options): bool
    {
        return ResponseCategory::fromStatusCode($response->getStatusCode())
            ->isEnabledIn($options->cacheStatuses);
    }

    private function isIdempotentMethod(Request $request): bool
    {
        return in_array($request->method(), ['POST', 'PUT', 'PATCH'], true);
    }
}
