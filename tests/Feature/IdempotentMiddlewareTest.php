<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Route;
use WendellAdriel\Idempotency\Enums\IdempotencyScope;
use WendellAdriel\Idempotency\Http\Middleware\Idempotent;
use WendellAdriel\Idempotency\Support\IdempotencyIndex;

beforeEach(function (): void {
    Route::middleware('web')->group(function (): void {
        Route::post('/orders', function () {
            test()->controllerExecutionCount++;

            return response()->json(['id' => 1]);
        })->middleware(Idempotent::class)->name('orders.store');

        Route::post('/orders/created', fn () => response()->json(['created' => true], 201)->header('X-Custom', 'value'))->middleware(Idempotent::class);

        Route::post('/orders/optional', fn () => response()->json(['id' => 1]))->middleware(Idempotent::using(required: false));

        Route::post('/orders/custom-header', function () {
            test()->controllerExecutionCount++;

            return response()->json(['id' => 1]);
        })->middleware(Idempotent::using(header: 'X-Idempotency-Key'));

        Route::post('/payments/registration', function () {
            test()->controllerExecutionCount++;

            return response()->json(['registered' => true]);
        })->middleware('idempotent')->name('payment-registration');

        Route::post('/orders/user-scope', fn () => response()->json(['id' => 1]))->middleware(Idempotent::using(scope: IdempotencyScope::User));

        Route::post('/orders/ip-scope', fn () => response()->json(['id' => 1]))->middleware(Idempotent::using(scope: IdempotencyScope::Ip));

        Route::post('/orders/global-scope', function () {
            test()->controllerExecutionCount++;

            return response()->json(['id' => 1]);
        })->middleware(Idempotent::using(scope: IdempotencyScope::Global));

        Route::post('/orders/query', fn () => response()->json(['id' => 1]))->middleware(Idempotent::class);

        Route::post('/orders/json-normalized', function () {
            test()->controllerExecutionCount++;

            return response()->json(['id' => 1]);
        })->middleware(Idempotent::class);

        Route::post('/orders/redirect', function (): Redirector|RedirectResponse {
            test()->controllerExecutionCount++;

            return redirect('/orders/1');
        })->middleware(Idempotent::class);

        Route::post('/orders/exception', function (): void {
            throw new RuntimeException('Boom');
        })->middleware(Idempotent::class);

        Route::post('/orders/validation', function (Request $request) {
            $request->validate(['item' => 'required']);

            return response()->json(['id' => 1]);
        })->middleware(Idempotent::class);

        Route::post('/refunds', fn () => response()->json(['type' => 'refund']))->middleware(Idempotent::class)->name('refunds.store');

        Route::post('/orders/upload', function () {
            test()->controllerExecutionCount++;

            return response()->json(['id' => 1]);
        })->middleware(Idempotent::class);

        Route::put('/orders', fn () => response()->json(['method' => 'put']))->middleware(Idempotent::class);

        Route::put('/orders/1', fn () => response()->json(['updated' => true]))->middleware(Idempotent::class);

        Route::patch('/orders/1', fn () => response()->json(['updated' => true]))->middleware(Idempotent::class);

        Route::get('/orders/index', fn () => response()->json(['id' => 1]))->middleware(Idempotent::class);

        Route::delete('/orders/1', fn () => response()->json(['deleted' => true]))->middleware(Idempotent::class);
    });

    $this->controllerExecutionCount = 0;
});

test('first request caches the response', function (): void {
    $this->postJson('/orders', ['item' => 'widget'], ['Idempotency-Key' => 'key-1'])
        ->assertOk()
        ->assertJson(['id' => 1])
        ->assertHeaderMissing('Idempotency-Replayed');
});

test('same key and payload replays the response with a header', function (): void {
    $this->postJson('/orders', ['item' => 'widget'], ['Idempotency-Key' => 'key-1']);

    $this->postJson('/orders', ['item' => 'widget'], ['Idempotency-Key' => 'key-1'])
        ->assertOk()
        ->assertJson(['id' => 1])
        ->assertHeader('Idempotency-Replayed', 'true');
});

test('replayed response preserves original status body and headers', function (): void {
    $this->postJson('/orders/created', ['item' => 'widget'], ['Idempotency-Key' => 'key-1']);

    $this->postJson('/orders/created', ['item' => 'widget'], ['Idempotency-Key' => 'key-1'])
        ->assertCreated()
        ->assertJson(['created' => true])
        ->assertHeader('X-Custom', 'value')
        ->assertHeader('Idempotency-Replayed', 'true');
});

test('replayed response does not execute the controller again', function (): void {
    $this->postJson('/orders', ['item' => 'widget'], ['Idempotency-Key' => 'key-1']);
    $this->postJson('/orders', ['item' => 'widget'], ['Idempotency-Key' => 'key-1']);

    expect($this->controllerExecutionCount)->toBe(1);
});

test('request input key replays standard form submissions', function (): void {
    $payload = [
        'item' => 'widget',
        '_idempotency_key' => 'form-key-1',
    ];

    $this->post('/orders', $payload)
        ->assertOk()
        ->assertHeaderMissing('Idempotency-Replayed');

    $this->post('/orders', $payload)
        ->assertOk()
        ->assertHeader('Idempotency-Replayed', 'true');

    expect($this->controllerExecutionCount)->toBe(1);
});

test('custom request input key replays submissions', function (): void {
    config()->set('idempotency.input', '_request_key');

    $payload = [
        'item' => 'widget',
        '_request_key' => 'form-key-1',
    ];

    $this->post('/orders', $payload);

    $this->post('/orders', $payload)
        ->assertOk()
        ->assertHeader('Idempotency-Replayed', 'true');

    expect($this->controllerExecutionCount)->toBe(1);
});

test('default request input is ignored when a custom name is configured', function (): void {
    config()->set('idempotency.input', '_request_key');

    $this->postJson('/orders', [
        'item' => 'widget',
        '_idempotency_key' => 'form-key-1',
    ])->assertBadRequest()
        ->assertJsonPath('message', 'Missing required header: Idempotency-Key');
});

test('header takes precedence over request input', function (): void {
    $payload = [
        'item' => 'widget',
        '_idempotency_key' => 'form-key-1',
    ];

    $this->postJson('/orders', $payload, ['Idempotency-Key' => 'header-key-1']);

    $this->postJson('/orders', $payload, ['Idempotency-Key' => 'header-key-2'])
        ->assertOk()
        ->assertHeaderMissing('Idempotency-Replayed');

    expect($this->controllerExecutionCount)->toBe(2);
});

test('header key containing zero takes precedence over request input', function (): void {
    $this->postJson('/orders', [
        'item' => 'widget',
        '_idempotency_key' => 'form-key-1',
    ], ['Idempotency-Key' => '0']);

    $index = $this->app->make(IdempotencyIndex::class);
    $members = $index->forMember(IdempotencyScope::Ip, '127.0.0.1');

    expect($members)->toHaveCount(1)
        ->and($members[0]->clientKey)->toBe('0');
});

test('empty header falls back to request input', function (): void {
    $payload = [
        'item' => 'widget',
        '_idempotency_key' => 'form-key-1',
    ];

    $this->postJson('/orders', $payload, ['Idempotency-Key' => '']);

    $this->postJson('/orders', $payload, ['Idempotency-Key' => ''])
        ->assertOk()
        ->assertHeader('Idempotency-Replayed', 'true');
});

test('same key with different payload returns 422', function (): void {
    $this->postJson('/orders', ['item' => 'widget'], ['Idempotency-Key' => 'key-1']);

    $this->postJson('/orders', ['item' => 'different'], ['Idempotency-Key' => 'key-1'])
        ->assertUnprocessable();
});

test('same key with different query string returns 422', function (): void {
    $this->postJson('/orders/query?source=web', ['item' => 'widget'], ['Idempotency-Key' => 'key-1']);

    $this->postJson('/orders/query?source=mobile', ['item' => 'widget'], ['Idempotency-Key' => 'key-1'])
        ->assertUnprocessable();
});

test('same key with different form-encoded payload returns 422', function (): void {
    // Regression test: real form-urlencoded/multipart POST requests never
    // reach RequestFingerprint::hashPayload() with a populated getContent(),
    // because PHP's SAPI consumes the raw body into $_POST/$_FILES before
    // Laravel sees it. $this->post() reproduces that same empty-content
    // behaviour, unlike $this->postJson().
    $this->post('/orders', ['item' => 'widget'], ['Idempotency-Key' => 'key-1'])
        ->assertOk();

    $this->post('/orders', ['item' => 'different'], ['Idempotency-Key' => 'key-1'])
        ->assertUnprocessable();

    expect($this->controllerExecutionCount)->toBe(1);
});

test('same key with identical form-encoded payload replays', function (): void {
    $this->post('/orders', ['item' => 'widget'], ['Idempotency-Key' => 'key-1'])
        ->assertOk()
        ->assertHeaderMissing('Idempotency-Replayed');

    $this->post('/orders', ['item' => 'widget'], ['Idempotency-Key' => 'key-1'])
        ->assertOk()
        ->assertHeader('Idempotency-Replayed', 'true');

    expect($this->controllerExecutionCount)->toBe(1);
});

test('same key with different non UTF-8 form fields returns 422', function (): void {
    $this->post('/orders', ['item' => "\xff"], ['Idempotency-Key' => 'key-1'])
        ->assertOk();

    $this->post('/orders', ['item' => "\xfe"], ['Idempotency-Key' => 'key-1'])
        ->assertUnprocessable();

    expect($this->controllerExecutionCount)->toBe(1);
});

test('same key with different uploaded file returns 422', function (): void {
    $this->post('/orders/upload', [
        'item' => 'widget',
        'document' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
    ], ['Idempotency-Key' => 'key-1'])->assertOk();

    $this->post('/orders/upload', [
        'item' => 'widget',
        'document' => UploadedFile::fake()->create('b.pdf', 20, 'application/pdf'),
    ], ['Idempotency-Key' => 'key-1'])->assertUnprocessable();

    expect($this->controllerExecutionCount)->toBe(1);
});

test('same key with same-named same-sized but different file content returns 422', function (): void {
    // Guards against a fix that only compares file name/size metadata
    // instead of actual content.
    $this->post('/orders/upload', [
        'item' => 'widget',
        'document' => UploadedFile::fake()->createWithContent('doc.txt', 'aaaa'),
    ], ['Idempotency-Key' => 'key-1'])->assertOk();

    $this->post('/orders/upload', [
        'item' => 'widget',
        'document' => UploadedFile::fake()->createWithContent('doc.txt', 'bbbb'),
    ], ['Idempotency-Key' => 'key-1'])->assertUnprocessable();

    expect($this->controllerExecutionCount)->toBe(1);
});

test('same key with different uploaded file mime type returns 422', function (): void {
    $firstSource = UploadedFile::fake()->createWithContent('document.txt', 'same-bytes');
    $firstFile = new UploadedFile($firstSource->getPathname(), 'document.txt', 'text/plain', null, true);

    $this->post('/orders/upload', [
        'item' => 'widget',
        'document' => $firstFile,
    ], ['Idempotency-Key' => 'key-1'])->assertOk();

    $secondSource = UploadedFile::fake()->createWithContent('document.txt', 'same-bytes');
    $secondFile = new UploadedFile($secondSource->getPathname(), 'document.txt', 'application/octet-stream', null, true);

    $this->post('/orders/upload', [
        'item' => 'widget',
        'document' => $secondFile,
    ], ['Idempotency-Key' => 'key-1'])->assertUnprocessable();

    expect($this->controllerExecutionCount)->toBe(1);
});

test('same key with different uploaded file paths returns 422', function (): void {
    $firstSource = UploadedFile::fake()->createWithContent('document.txt', 'same-bytes');
    $firstFile = new UploadedFile($firstSource->getPathname(), 'first/document.txt', 'text/plain', null, true);

    $this->post('/orders/upload', [
        'item' => 'widget',
        'document' => $firstFile,
    ], ['Idempotency-Key' => 'key-1'])->assertOk();

    $secondSource = UploadedFile::fake()->createWithContent('document.txt', 'same-bytes');
    $secondFile = new UploadedFile($secondSource->getPathname(), 'second/document.txt', 'text/plain', null, true);

    $this->post('/orders/upload', [
        'item' => 'widget',
        'document' => $secondFile,
    ], ['Idempotency-Key' => 'key-1'])->assertUnprocessable();

    expect($this->controllerExecutionCount)->toBe(1);
});

test('same key with identical uploaded file replays', function (): void {
    $makeFile = fn (): UploadedFile => UploadedFile::fake()->createWithContent('doc.txt', 'same-bytes');

    $this->post('/orders/upload', [
        'item' => 'widget',
        'document' => $makeFile(),
    ], ['Idempotency-Key' => 'key-1'])
        ->assertOk()
        ->assertHeaderMissing('Idempotency-Replayed');

    $this->post('/orders/upload', [
        'item' => 'widget',
        'document' => $makeFile(),
    ], ['Idempotency-Key' => 'key-1'])
        ->assertOk()
        ->assertHeader('Idempotency-Replayed', 'true');

    expect($this->controllerExecutionCount)->toBe(1);
});

test('same key with different files in an array-of-files field returns 422', function (): void {
    $this->post('/orders/upload', [
        'item' => 'widget',
        'photos' => [
            UploadedFile::fake()->createWithContent('one.jpg', 'photo-one'),
            UploadedFile::fake()->createWithContent('two.jpg', 'photo-two'),
        ],
    ], ['Idempotency-Key' => 'key-1'])->assertOk();

    $this->post('/orders/upload', [
        'item' => 'widget',
        'photos' => [
            UploadedFile::fake()->createWithContent('one.jpg', 'photo-one'),
            UploadedFile::fake()->createWithContent('two.jpg', 'DIFFERENT'),
        ],
    ], ['Idempotency-Key' => 'key-1'])->assertUnprocessable();

    expect($this->controllerExecutionCount)->toBe(1);
});

test('same key with identical files in an array-of-files field replays', function (): void {
    $makePhotos = fn (): array => [
        UploadedFile::fake()->createWithContent('one.jpg', 'photo-one'),
        UploadedFile::fake()->createWithContent('two.jpg', 'photo-two'),
    ];

    $this->post('/orders/upload', [
        'item' => 'widget',
        'photos' => $makePhotos(),
    ], ['Idempotency-Key' => 'key-1'])
        ->assertOk()
        ->assertHeaderMissing('Idempotency-Replayed');

    $this->post('/orders/upload', [
        'item' => 'widget',
        'photos' => $makePhotos(),
    ], ['Idempotency-Key' => 'key-1'])
        ->assertOk()
        ->assertHeader('Idempotency-Replayed', 'true');

    expect($this->controllerExecutionCount)->toBe(1);
});

test('same key with differently failed uploads is not treated as an identical payload', function (): void {
    // Two failed uploads must not collapse to the same fingerprint just
    // because neither has readable content - they can fail for different
    // reasons and are not necessarily "the same request".
    $this->post('/orders/upload', [
        'item' => 'widget',
        'document' => new UploadedFile(
            sys_get_temp_dir() . '/does-not-matter',
            'a.pdf',
            'application/pdf',
            UPLOAD_ERR_INI_SIZE,
            true,
        ),
    ], ['Idempotency-Key' => 'key-1'])->assertOk();

    $this->post('/orders/upload', [
        'item' => 'widget',
        'document' => new UploadedFile(
            sys_get_temp_dir() . '/does-not-matter',
            'a.pdf',
            'application/pdf',
            UPLOAD_ERR_FORM_SIZE,
            true,
        ),
    ], ['Idempotency-Key' => 'key-1'])->assertUnprocessable();

    expect($this->controllerExecutionCount)->toBe(1);
});

test('same key on different route does not collide', function (): void {
    $this->postJson('/orders', ['item' => 'widget'], ['Idempotency-Key' => 'key-1']);

    $this->postJson('/refunds', ['item' => 'widget'], ['Idempotency-Key' => 'key-1'])
        ->assertOk()
        ->assertJson(['type' => 'refund'])
        ->assertHeaderMissing('Idempotency-Replayed');
});

test('same key on different method does not collide', function (): void {
    $this->postJson('/orders', ['item' => 'widget'], ['Idempotency-Key' => 'key-1']);

    $this->putJson('/orders', ['item' => 'widget'], ['Idempotency-Key' => 'key-1'])
        ->assertOk()
        ->assertJson(['method' => 'put'])
        ->assertHeaderMissing('Idempotency-Replayed');
});

test('concurrent in flight duplicate returns 409 with retry after', function (): void {
    /** @var Cache $cache */
    $cache = $this->app->make(Cache::class);

    $storageKey = hash('xxh128', implode('|', [
        'orders.store',
        'POST',
        'ip:127.0.0.1',
        'Idempotency-Key',
        'key-conflict',
    ]));

    $cache->lock('idempotent-lock:' . $storageKey, 10)->get();

    $this->postJson('/orders', ['item' => 'widget'], ['Idempotency-Key' => 'key-conflict'])
        ->assertConflict()
        ->assertHeader('Retry-After', '1');
});

test('missing key returns 400 when required', function (): void {
    $this->postJson('/orders', ['item' => 'widget'])->assertBadRequest();
});

test('empty and non-string request input keys return 400 when required', function (): void {
    foreach (['', ['nested-key'], 123] as $clientKey) {
        $this->postJson('/orders', [
            'item' => 'widget',
            '_idempotency_key' => $clientKey,
        ])->assertBadRequest()
            ->assertJsonPath('message', 'Missing required header: Idempotency-Key');
    }
});

test('missing key passes through when optional', function (): void {
    $this->postJson('/orders/optional', ['item' => 'widget'])
        ->assertOk()
        ->assertJson(['id' => 1]);
});

test('invalid request input key passes through without an index entry when optional', function (): void {
    $this->postJson('/orders/optional', [
        'item' => 'widget',
        '_idempotency_key' => ['nested-key'],
    ])->assertOk()
        ->assertJson(['id' => 1]);

    $index = $this->app->make(IdempotencyIndex::class);

    expect($index->all())->toBe([]);
});

test('custom header name works when configured on middleware', function (): void {
    $this->postJson('/orders/custom-header', ['item' => 'widget'], ['X-Idempotency-Key' => 'custom-key-1']);

    $this->postJson('/orders/custom-header', ['item' => 'widget'], ['X-Idempotency-Key' => 'custom-key-1'])
        ->assertOk()
        ->assertHeader('Idempotency-Replayed', 'true');

    expect($this->controllerExecutionCount)->toBe(1);
});

test('default header is ignored when custom header is configured', function (): void {
    $this->postJson('/orders/custom-header', ['item' => 'widget'], ['Idempotency-Key' => 'key-1'])
        ->assertBadRequest();
});

test('route alias replays inertia-like submissions when x idempotency header is configured', function (): void {
    config()->set('idempotency.header', 'X-Idempotency-Key');

    $headers = [
        'X-Idempotency-Key' => 'payment-key-1',
        'X-Inertia' => 'true',
        'Accept' => 'text/html, application/xhtml+xml',
    ];

    $this->postJson('/payments/registration', ['account' => 'bank'], $headers)
        ->assertOk()
        ->assertHeaderMissing('Idempotency-Replayed');

    $this->postJson('/payments/registration', ['account' => 'bank'], $headers)
        ->assertOk()
        ->assertJson(['registered' => true])
        ->assertHeader('Idempotency-Replayed', 'true');

    $index = $this->app->make(IdempotencyIndex::class);

    expect($this->controllerExecutionCount)->toBe(1)
        ->and($index->forMember(IdempotencyScope::Ip, '127.0.0.1'))->toHaveCount(1);
});

test('route alias replays x idempotency header submissions for authenticated users', function (): void {
    config()->set('idempotency.header', 'X-Idempotency-Key');

    $this->actingAs(new GenericUser(['id' => 7]));

    $this->postJson('/payments/registration', ['account' => 'bank'], ['X-Idempotency-Key' => 'payment-key-1']);

    $this->postJson('/payments/registration', ['account' => 'bank'], ['X-Idempotency-Key' => 'payment-key-1'])
        ->assertOk()
        ->assertHeader('Idempotency-Replayed', 'true');

    $index = $this->app->make(IdempotencyIndex::class);

    expect($this->controllerExecutionCount)->toBe(1)
        ->and($index->forMember(IdempotencyScope::User, '7'))->toHaveCount(1);
});

test('route alias with default config rejects x idempotency header only', function (): void {
    $this->postJson('/payments/registration', ['account' => 'bank'], ['X-Idempotency-Key' => 'payment-key-1'])
        ->assertBadRequest()
        ->assertJsonPath('message', 'Missing required header: Idempotency-Key');
});

test('configured x idempotency header makes the route alias replay correctly', function (): void {
    config()->set('idempotency.header', 'X-Idempotency-Key');

    $this->postJson('/payments/registration', ['account' => 'bank'], ['X-Idempotency-Key' => 'payment-key-1']);

    $this->postJson('/payments/registration', ['account' => 'bank'], ['X-Idempotency-Key' => 'payment-key-1'])
        ->assertOk()
        ->assertHeader('Idempotency-Replayed', 'true');
});

test('repeated submissions with regenerated keys create separate index entries', function (): void {
    config()->set('idempotency.header', 'X-Idempotency-Key');

    $this->actingAs(new GenericUser(['id' => 7]));

    $this->postJson('/payments/registration', ['account' => 'bank'], ['X-Idempotency-Key' => 'payment-key-1']);
    $this->postJson('/payments/registration', ['account' => 'bank'], ['X-Idempotency-Key' => 'payment-key-2'])
        ->assertOk()
        ->assertHeaderMissing('Idempotency-Replayed');

    $index = $this->app->make(IdempotencyIndex::class);
    $members = $index->forMember(IdempotencyScope::User, '7');

    expect($this->controllerExecutionCount)->toBe(2)
        ->and($members)->toHaveCount(2)
        ->and(array_map(fn ($member) => $member->clientKey, $members))->toBe([
            'payment-key-1',
            'payment-key-2',
        ]);
});

test('user scope isolates different authenticated users', function (): void {
    $userOne = new GenericUser(['id' => 1]);
    $userTwo = new GenericUser(['id' => 2]);

    $this->actingAs($userOne)
        ->postJson('/orders/user-scope', ['item' => 'widget'], ['Idempotency-Key' => 'key-1']);

    $this->actingAs($userTwo)
        ->postJson('/orders/user-scope', ['item' => 'widget'], ['Idempotency-Key' => 'key-1'])
        ->assertOk()
        ->assertHeaderMissing('Idempotency-Replayed');
});

test('user scope falls back to ip for guests', function (): void {
    $this->postJson('/orders/user-scope', ['item' => 'widget'], ['Idempotency-Key' => 'key-1']);

    $this->postJson('/orders/user-scope', ['item' => 'widget'], ['Idempotency-Key' => 'key-1'])
        ->assertOk()
        ->assertHeader('Idempotency-Replayed', 'true');
});

test('ip scope isolates by ip', function (): void {
    $this->postJson('/orders/ip-scope', ['item' => 'widget'], ['Idempotency-Key' => 'key-1'])
        ->assertOk();

    $this->postJson('/orders/ip-scope', ['item' => 'widget'], ['Idempotency-Key' => 'key-1'])
        ->assertOk()
        ->assertHeader('Idempotency-Replayed', 'true');
});

test('global scope ignores user and ip segmentation', function (): void {
    $userOne = new GenericUser(['id' => 1]);
    $userTwo = new GenericUser(['id' => 2]);

    $this->actingAs($userOne)
        ->postJson('/orders/global-scope', ['item' => 'widget'], ['Idempotency-Key' => 'key-1']);

    $this->actingAs($userTwo)
        ->postJson('/orders/global-scope', ['item' => 'widget'], ['Idempotency-Key' => 'key-1'])
        ->assertOk()
        ->assertHeader('Idempotency-Replayed', 'true');

    expect($this->controllerExecutionCount)->toBe(1);
});

test('non target methods pass through untouched', function (): void {
    $this->getJson('/orders/index')->assertOk();
    $this->deleteJson('/orders/1')->assertOk();
});

test('json normalization avoids false mismatches caused by key order', function (): void {
    $this->postJson('/orders/json-normalized', ['b' => 2, 'a' => 1], ['Idempotency-Key' => 'key-1']);

    $this->postJson('/orders/json-normalized', ['a' => 1, 'b' => 2], ['Idempotency-Key' => 'key-1'])
        ->assertOk()
        ->assertHeader('Idempotency-Replayed', 'true');

    expect($this->controllerExecutionCount)->toBe(1);
});

test('redirects can be replayed', function (): void {
    $this->post('/orders/redirect', ['item' => 'widget'], ['Idempotency-Key' => 'key-1']);

    $this->post('/orders/redirect', ['item' => 'widget'], ['Idempotency-Key' => 'key-1'])
        ->assertRedirect('/orders/1')
        ->assertHeader('Idempotency-Replayed', 'true');

    expect($this->controllerExecutionCount)->toBe(1);
});

test('validation exceptions do not poison the stored response', function (): void {
    $this->postJson('/orders/validation', [], ['Idempotency-Key' => 'key-1'])
        ->assertUnprocessable();

    $this->postJson('/orders/validation', ['item' => 'widget'], ['Idempotency-Key' => 'key-2'])
        ->assertOk()
        ->assertJson(['id' => 1]);
});

test('lock is released after downstream exceptions', function (): void {
    $this->withoutExceptionHandling();

    try {
        $this->postJson('/orders/exception', ['item' => 'widget'], ['Idempotency-Key' => 'key-1']);
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Boom');
    }

    $response = null;

    try {
        $this->postJson('/orders/exception', ['item' => 'widget'], ['Idempotency-Key' => 'key-1']);
    } catch (RuntimeException $exception) {
        $response = $exception;
    }

    expect($response)->toBeInstanceOf(RuntimeException::class);
});

test('using generates the correct middleware string', function (): void {
    expect(Idempotent::using())
        ->toBe(Idempotent::class . ':3600,1,user,Idempotency-Key,10')
        ->and(Idempotent::using(ttl: 600))
        ->toBe(Idempotent::class . ':600,1,user,Idempotency-Key,10')
        ->and(Idempotent::using(lockTimeout: 45))
        ->toBe(Idempotent::class . ':3600,1,user,Idempotency-Key,45')
        ->and(Idempotent::using(required: false, scope: IdempotencyScope::Ip, header: 'X-Idempotency-Key'))
        ->toBe(Idempotent::class . ':3600,0,ip,X-Idempotency-Key,10');
});

test('zero lock_timeout on using() throws', function (): void {
    expect(fn (): string => Idempotent::using(lockTimeout: 0))
        ->toThrow(InvalidArgumentException::class, 'The lock_timeout must be a positive integer (>= 1).');
});

test('negative lock_timeout on using() throws', function (): void {
    expect(fn (): string => Idempotent::using(lockTimeout: -1))
        ->toThrow(InvalidArgumentException::class, 'The lock_timeout must be a positive integer (>= 1).');
});

test('zero ttl on using() throws', function (): void {
    expect(fn (): string => Idempotent::using(ttl: 0))
        ->toThrow(InvalidArgumentException::class, 'The ttl must be a positive integer (>= 1).');
});

test('negative ttl on using() throws', function (): void {
    expect(fn (): string => Idempotent::using(ttl: -1))
        ->toThrow(InvalidArgumentException::class, 'The ttl must be a positive integer (>= 1).');
});

test('ttl of exactly 1 is accepted and still replays', function (): void {
    Route::middleware('web')->group(function (): void {
        Route::post('/orders/min-ttl', fn () => response()->json(['id' => 1]))->middleware(Idempotent::using(ttl: 1));
    });

    $this->postJson('/orders/min-ttl', ['item' => 'widget'], ['Idempotency-Key' => 'key-1'])
        ->assertOk()
        ->assertHeaderMissing('Idempotency-Replayed');

    $this->postJson('/orders/min-ttl', ['item' => 'widget'], ['Idempotency-Key' => 'key-1'])
        ->assertOk()
        ->assertHeader('Idempotency-Replayed', 'true');
});

test('zero configured ttl throws instead of silently skipping the cache', function (): void {
    // Regression: Cache::put() with a ttl <= 0 stores nothing, which used to make
    // the middleware silently stop deduplicating requests instead of failing loudly.
    config()->set('idempotency.ttl', 0);

    Route::middleware('web')->group(function (): void {
        Route::post('/orders/zero-ttl', fn () => response()->json(['id' => 1]))->middleware(Idempotent::class);
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->postJson('/orders/zero-ttl', ['item' => 'widget'], ['Idempotency-Key' => 'key-1']))
        ->toThrow(InvalidArgumentException::class, 'The ttl must be a positive integer (>= 1).');
});

test('using() accepts the legacy positional argument order', function (): void {
    // Regression: the public API must keep the pre-lockTimeout positional order
    // (ttl, required, scope, header) working unchanged.
    // lockTimeout is omitted, so it falls back to the config default (10).
    expect(Idempotent::using(600, false, IdempotencyScope::Ip, 'X-Idempotency-Key'))
        ->toBe(Idempotent::class . ':600,0,ip,X-Idempotency-Key,10');
});

test('omitted middleware options pull defaults from config', function (): void {
    config()->set('idempotency.ttl', 120);
    config()->set('idempotency.required', false);
    config()->set('idempotency.scope', 'global');
    config()->set('idempotency.header', 'X-Config-Idempotency-Key');

    Route::post('/orders/config-defaults', fn () => response()->json(['id' => 1]))->middleware(Idempotent::class);

    $this->postJson('/orders/config-defaults', ['item' => 'widget'])
        ->assertOk()
        ->assertJson(['id' => 1]);

    $this->postJson('/orders/config-defaults', ['item' => 'widget'], ['X-Config-Idempotency-Key' => 'key-1'])
        ->assertOk()
        ->assertHeaderMissing('Idempotency-Replayed');

    $this->postJson('/orders/config-defaults', ['item' => 'widget'], ['X-Config-Idempotency-Key' => 'key-1'])
        ->assertOk()
        ->assertHeader('Idempotency-Replayed', 'true');
});

test('conflict path does not cache a placeholder response', function (): void {
    /** @var Cache $cache */
    $cache = $this->app->make(Cache::class);

    $storageKey = hash('xxh128', implode('|', [
        'orders.store',
        'POST',
        'ip:127.0.0.1',
        'Idempotency-Key',
        'key-conflict',
    ]));

    $lock = $cache->lock('idempotent-lock:' . $storageKey, 10);
    $lock->get();

    $this->postJson('/orders', ['item' => 'widget'], ['Idempotency-Key' => 'key-conflict'])
        ->assertConflict();

    $lock->release();

    $this->postJson('/orders', ['item' => 'widget'], ['Idempotency-Key' => 'key-conflict'])
        ->assertOk()
        ->assertJson(['id' => 1]);

    expect($this->controllerExecutionCount)->toBe(1);
});

test('patch requests are idempotency managed', function (): void {
    $this->patchJson('/orders/1', ['item' => 'widget'])->assertBadRequest();
});

test('put requests are idempotency managed', function (): void {
    $this->putJson('/orders/1', ['item' => 'widget'])->assertBadRequest();
});

test('stored response writes a matching index member', function (): void {
    $this->postJson('/orders', ['item' => 'widget'], ['Idempotency-Key' => 'key-1']);

    $index = $this->app->make(IdempotencyIndex::class);
    $members = $index->forMember(IdempotencyScope::Ip, '127.0.0.1');

    expect($members)->toHaveCount(1);

    $member = $members[0];
    expect($member->scope)->toBe(IdempotencyScope::Ip)
        ->and($member->identifier)->toBe('127.0.0.1')
        ->and($member->clientKey)->toBe('key-1')
        ->and($member->route)->toBe('orders.store')
        ->and($member->method)->toBe('POST')
        ->and($member->status)->toBe(200)
        ->and($member->expiresAt)->toBeGreaterThan($member->createdAt);
});

test('replayed request does not add or mutate the index entry', function (): void {
    $this->postJson('/orders', ['item' => 'widget'], ['Idempotency-Key' => 'key-1']);

    $index = $this->app->make(IdempotencyIndex::class);
    $before = array_map(fn ($m) => $m->toArray(), $index->forMember(IdempotencyScope::Ip, '127.0.0.1'));

    $this->postJson('/orders', ['item' => 'widget'], ['Idempotency-Key' => 'key-1'])
        ->assertHeader('Idempotency-Replayed', 'true');

    $after = array_map(fn ($m) => $m->toArray(), $index->forMember(IdempotencyScope::Ip, '127.0.0.1'));

    expect($after)->toBe($before);
});

test('user scope stores the member under the user scope index', function (): void {
    $this->actingAs(new GenericUser(['id' => 7]))
        ->postJson('/orders/user-scope', ['item' => 'widget'], ['Idempotency-Key' => 'key-1']);

    $index = $this->app->make(IdempotencyIndex::class);
    $members = $index->forMember(IdempotencyScope::User, '7');

    expect($members)->toHaveCount(1)
        ->and($members[0]->scope)->toBe(IdempotencyScope::User)
        ->and($members[0]->identifier)->toBe('7');
});

test('ip scope stores the member under the ip scope index', function (): void {
    $this->postJson('/orders/ip-scope', ['item' => 'widget'], ['Idempotency-Key' => 'key-1']);

    $index = $this->app->make(IdempotencyIndex::class);
    $members = $index->forMember(IdempotencyScope::Ip, '127.0.0.1');

    expect($members)->toHaveCount(1)
        ->and($members[0]->scope)->toBe(IdempotencyScope::Ip)
        ->and($members[0]->identifier)->toBe('127.0.0.1');
});

test('global scope stores the member under the global scope index', function (): void {
    $this->postJson('/orders/global-scope', ['item' => 'widget'], ['Idempotency-Key' => 'key-1']);

    $index = $this->app->make(IdempotencyIndex::class);
    $members = $index->forMember(IdempotencyScope::Global, '');

    expect($members)->toHaveCount(1)
        ->and($members[0]->scope)->toBe(IdempotencyScope::Global)
        ->and($members[0]->identifier)->toBe('');
});

test('routes accessed by uri record the uri in the route field', function (): void {
    $this->postJson('/orders/ip-scope', ['item' => 'widget'], ['Idempotency-Key' => 'key-1']);

    $index = $this->app->make(IdempotencyIndex::class);
    $members = $index->forMember(IdempotencyScope::Ip, '127.0.0.1');

    expect($members[0]->route)->toBe('/orders/ip-scope');
});

test('non target methods do not write an index entry', function (): void {
    $this->getJson('/orders/index')->assertOk();
    $this->deleteJson('/orders/1')->assertOk();

    $index = $this->app->make(IdempotencyIndex::class);

    expect($index->all())->toBe([]);
});

test('missing optional header does not write an index entry', function (): void {
    $this->postJson('/orders/optional', ['item' => 'widget'])->assertOk();

    $index = $this->app->make(IdempotencyIndex::class);

    expect($index->all())->toBe([]);
});

test('exception inside next does not write an index entry', function (): void {
    $this->withoutExceptionHandling();

    try {
        $this->postJson('/orders/exception', ['item' => 'widget'], ['Idempotency-Key' => 'key-exc']);
    } catch (RuntimeException) {
        // expected
    }

    $index = $this->app->make(IdempotencyIndex::class);

    expect($index->all())->toBe([]);
});

test('conflict response does not write an index entry', function (): void {
    /** @var Cache $cache */
    $cache = $this->app->make(Cache::class);

    $storageKey = hash('xxh128', implode('|', [
        'orders.store',
        'POST',
        'ip:127.0.0.1',
        'Idempotency-Key',
        'key-conflict',
    ]));

    $cache->lock('idempotent-lock:' . $storageKey, 10)->get();

    $this->postJson('/orders', ['item' => 'widget'], ['Idempotency-Key' => 'key-conflict'])
        ->assertConflict();

    $index = $this->app->make(IdempotencyIndex::class);

    expect($index->all())->toBe([]);
});
