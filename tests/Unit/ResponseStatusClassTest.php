<?php

declare(strict_types=1);

use WendellAdriel\Idempotency\Enums\ResponseStatusClass;

test('it maps status codes to their response class', function (int $status, ResponseStatusClass $expected): void {
    expect(ResponseStatusClass::fromStatusCode($status))->toBe($expected);
})->with([
    [100, ResponseStatusClass::Informational],
    [199, ResponseStatusClass::Informational],
    [200, ResponseStatusClass::Success],
    [201, ResponseStatusClass::Success],
    [299, ResponseStatusClass::Success],
    [300, ResponseStatusClass::Redirection],
    [302, ResponseStatusClass::Redirection],
    [399, ResponseStatusClass::Redirection],
    [400, ResponseStatusClass::ClientError],
    [422, ResponseStatusClass::ClientError],
    [499, ResponseStatusClass::ClientError],
    [500, ResponseStatusClass::ServerError],
    [503, ResponseStatusClass::ServerError],
]);

test('it reads a flag from a status map', function (): void {
    $statuses = ['client_error' => false, 'success' => true];

    expect(ResponseStatusClass::ClientError->isEnabledIn($statuses))->toBeFalse()
        ->and(ResponseStatusClass::Success->isEnabledIn($statuses))->toBeTrue();
});

test('it treats a missing flag as enabled', function (): void {
    // Keeps a partial map - from a published config file written before a class
    // existed - caching everything it does not mention.
    expect(ResponseStatusClass::ServerError->isEnabledIn([]))->toBeTrue()
        ->and(ResponseStatusClass::Informational->isEnabledIn(['success' => false]))->toBeTrue();
});
