<?php

declare(strict_types=1);

use WendellAdriel\Idempotency\Enums\ResponseCategory;

test('it maps status codes to their response category', function (int $status, ResponseCategory $expected): void {
    expect(ResponseCategory::fromStatusCode($status))->toBe($expected);
})->with([
    [100, ResponseCategory::Informational],
    [199, ResponseCategory::Informational],
    [200, ResponseCategory::Success],
    [201, ResponseCategory::Success],
    [299, ResponseCategory::Success],
    [300, ResponseCategory::Redirection],
    [302, ResponseCategory::Redirection],
    [399, ResponseCategory::Redirection],
    [400, ResponseCategory::ClientError],
    [422, ResponseCategory::ClientError],
    [499, ResponseCategory::ClientError],
    [500, ResponseCategory::ServerError],
    [503, ResponseCategory::ServerError],
]);

test('it reads a flag from a category map', function (): void {
    $statuses = ['client_error' => false, 'success' => true];

    expect(ResponseCategory::ClientError->isEnabledIn($statuses))->toBeFalse()
        ->and(ResponseCategory::Success->isEnabledIn($statuses))->toBeTrue();
});

test('it treats a missing flag as enabled', function (): void {
    // Keeps a partial map - from a published config file written before a category
    // existed - caching everything it does not mention.
    expect(ResponseCategory::ServerError->isEnabledIn([]))->toBeTrue()
        ->and(ResponseCategory::Informational->isEnabledIn(['success' => false]))->toBeTrue();
});
