<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

test('directive renders a hidden input with a generated key', function (): void {
    $html = trim(Blade::render('@idempotency'));

    expect($html)->toMatch('/^<input type="hidden" name="_idempotency_key" value="[A-Za-z0-9]{64}" autocomplete="off">$/');
});

test('directive uses the configured request input name', function (): void {
    config()->set('idempotency.input', '_request_key');

    $html = trim(Blade::render('@idempotency'));

    expect($html)->toContain('name="_request_key"');
});

test('directive accepts a custom key', function (): void {
    $html = trim(Blade::render("@idempotency('custom-key')"));

    expect($html)->toBe('<input type="hidden" name="_idempotency_key" value="custom-key" autocomplete="off">');
});

test('directive escapes a custom key', function (): void {
    $html = trim(Blade::render('@idempotency($key)', [
        'key' => '"><script>alert(1)</script>',
    ]));

    expect($html)
        ->toContain('value="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"')
        ->not->toContain('<script>');
});
