<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use ParticleAcademy\HttpGuard\Client\ErrorNormaliser;
use ParticleAcademy\HttpGuard\Settings;
use ParticleAcademy\HttpGuard\Tests\Support\Rows;
use ParticleAcademy\HttpGuard\Tests\Support\TestApp;

/*
 * Hostile requests beyond the shared rows, each naming a mistake. Written
 * before src/ existed: every one failed with "class not found" first.
 */

const CANARY = 'SQLSTATE[42S02] users@10.0.0.4 /srv/app/.env';

function guarded(array $config = [], bool $debug = false, ?callable $routes = null)
{
    return TestApp::make($config, $routes ?? function (): void {
        Route::post('/api/orders', function (Request $request) {
            TestApp::$seen['body'] = $request->getContent();
            TestApp::$seen['input'] = $request->all();

            return response()->json(['ok' => true]);
        });
    }, $debug);
}

function json_request(string $uri, string $body, array $headers = [], &$stream = null): Request
{
    return TestApp::request($uri, 'POST', ['content-type' => 'application/json', 'accept' => 'application/json'] + $headers, $body, $stream);
}

// --- order: before anything parses the body ---------------------------------

it('refuses a declared oversize body before TrimStrings or anything else reads it', function (): void {
    $app = guarded();
    $body = '{"a":"'.str_repeat('x', 2_000_000).'"}';
    $response = TestApp::handle($app, json_request('/api/orders', $body, ['content-length' => (string) strlen($body)], $stream));

    expect($response->getStatusCode())->toBe(413);
    expect(ftell($stream))->toBe(0, 'the body was read in order to refuse it');
    expect(TestApp::$seen)->toBe([]);
});

it('catches a lying Content-Length by reading at most the cap plus one byte', function (): void {
    $app = guarded(['fancy-http-guard.limits' => ['maxBodyBytes' => 100]]);
    $response = TestApp::handle($app, json_request('/api/orders', str_repeat('[', 5000), ['content-length' => '5'], $stream));

    expect($response->getStatusCode())->toBe(413);
    expect(ftell($stream))->toBeLessThanOrEqual(101);
});

it('refuses 1e400 that PHP itself would silently turn into INF', function (): void {
    expect(json_decode('{"a":1e400}')->a)->toBe(INF); // the hazard is real

    $response = TestApp::handle(guarded(), json_request('/api/orders', '{"order":{"lines":[1,1e400]}}'));
    expect($response->getStatusCode())->toBe(422);
    expect(json_decode($response->getContent(), true)['errors'])
        ->toBe([['error' => 'not_finite', 'message' => 'This must be a finite number.', 'field' => 'order.lines.1']]);
    expect(TestApp::$seen)->toBe([]);
});

it('stops a depth bomb at the first level over, with no grammar fault reached', function (): void {
    $response = TestApp::handle(guarded(), json_request('/api/orders', str_repeat('[', 1_000_000)));
    expect($response->getStatusCode())->toBe(422);
    expect(json_decode($response->getContent(), true)['errors'][0]['limit'])->toBe(['name' => 'maxDepth', 'max' => 32]);
});

it('stops an object bomb like a list bomb', function (): void {
    $body = '{'.implode(',', array_map(fn ($i) => "\"k{$i}\":0", range(0, 20_000))).'}';
    $response = TestApp::handle(guarded(), json_request('/api/orders', $body));
    expect(json_decode($response->getContent(), true)['errors'][0]['error'])->toBe('list_too_long');
});

it('hands a passing body to the controller byte for byte', function (): void {
    $body = json_encode(['rows' => array_map(fn ($i) => [$i, $i * 1.5, 'x'], range(1, 2000))]);
    $response = TestApp::handle(guarded(), json_request('/api/orders', $body));
    expect($response->getStatusCode())->toBe(200);
    expect(TestApp::$seen['body'])->toBe($body);
    expect(TestApp::$seen['input']['rows'][1999][0])->toBe(2000);
});

it('treats an empty CONTENT_LENGTH as absent, as PHP-FPM sends on every GET', function (): void {
    $app = guarded(routes: fn () => Route::get('/ping', fn () => 'pong'));
    $request = Request::create('/ping', 'GET', [], [], [], ['CONTENT_LENGTH' => '']);
    expect(TestApp::handle($app, $request)->getStatusCode())->toBe(200);
});

it('does not scan multipart, which PHP parsed before any PHP code ran', function (): void {
    $app = guarded();
    $response = TestApp::handle($app, TestApp::request('/api/orders', 'POST', ['content-type' => 'multipart/form-data; boundary=x', 'accept' => 'application/json'], 'NaN'));
    expect($response->getStatusCode())->toBe(200);
});

// --- per route --------------------------------------------------------------

it('applies per-route limits from the route macro, before parsing', function (): void {
    $app = guarded(routes: function (): void {
        Route::post('/api/imports', fn () => response()->json(['ok' => true]))->guard('upload');
        Route::post('/api/tiny', fn () => response()->json(['ok' => true]))->guard(['maxBodyBytes' => 8]);
        Route::post('/api/orders', fn () => response()->json(['ok' => true]));
    });
    $big = str_repeat('x', 2_000_000);
    $octet = fn (string $uri, string $body) => TestApp::request($uri, 'POST', ['content-type' => 'application/octet-stream', 'accept' => 'application/json'], $body);

    expect(TestApp::handle($app, $octet('/api/orders', $big))->getStatusCode())->toBe(413);
    expect(TestApp::handle($app, $octet('/api/imports', $big))->getStatusCode())->toBe(200);
    expect(TestApp::handle($app, json_request('/api/tiny', '{"a":12345}'))->getStatusCode())->toBe(413);
});

it('applies per-route limits by route name from config', function (): void {
    $app = guarded(['fancy-http-guard.routes' => ['imports.store' => ['maxListLength' => 2]]], routes: function (): void {
        Route::post('/api/imports', fn () => response()->json(['ok' => true]))->name('imports.store');
    });
    $response = TestApp::handle($app, json_request('/api/imports', '[1,2,3]'));
    expect($response->getStatusCode())->toBe(422);
    expect(json_decode($response->getContent(), true)['errors'][0]['limit']['max'])->toBe(2);
});

it('refuses bad limits when the route is declared, not on the first request', function (): void {
    TestApp::boot(guarded(routes: function (): void {
        Route::post('/x', fn () => 'x')->guard(['maxDepth' => 0]);
    }));
})->throws(InvalidArgumentException::class, 'fancy-http-guard');

it('refuses a bad setting by name', function (): void {
    Settings::resolve(['maxBodyByte' => 10]);
})->throws(InvalidArgumentException::class, 'unknown limit');

// --- exceptions never reach the response ------------------------------------

it('renders a crash as a fixed 500 even with APP_DEBUG on', function (): void {
    $app = guarded(debug: true, routes: fn () => Route::post('/boom', fn () => throw new RuntimeException(CANARY)));
    $response = TestApp::handle($app, json_request('/boom', '{}'));

    expect($response->getStatusCode())->toBe(500);
    expect($response->getContent())->not->toContain('SQLSTATE')->not->toContain('10.0.0.4')->not->toContain('trace');
    expect(json_decode($response->getContent(), true)['error'])->toBe('server_error');
});

it('does not show a model-not-found message, which names the model class', function (): void {
    $app = guarded(routes: fn () => Route::post('/users/42', fn () => throw (new ModelNotFoundException)->setModel('App\Models\User', [42])));
    $body = json_decode(TestApp::handle($app, json_request('/users/42', '{}'))->getContent(), true);

    expect($body['message'])->toBe('That could not be found.');
});

it('renders PostTooLargeException and AuthenticationException as the envelope', function (): void {
    $app = guarded(routes: function (): void {
        Route::post('/big', fn () => throw new PostTooLargeException);
        Route::post('/auth', fn () => throw new AuthenticationException);
    });
    expect(json_decode(TestApp::handle($app, json_request('/big', '{}'))->getContent(), true))
        ->toMatchArray(['error' => 'body_too_large', 'message' => 'This request is too large for the server.']);
    expect(json_decode(TestApp::handle($app, json_request('/auth', '{}'))->getContent(), true))
        ->toMatchArray(['error' => 'unauthenticated', 'message' => 'Please sign in to continue.']);
});

it('leaves an HTML request to Laravel', function (): void {
    $app = guarded(routes: fn () => Route::get('/page', fn () => abort(404)));
    $response = TestApp::handle($app, Request::create('/page', 'GET'));
    expect($response->getStatusCode())->toBe(404);
    expect($response->headers->get('content-type'))->not->toBe('application/json');
});

// --- Inertia ----------------------------------------------------------------

it('turns a guard 422 on an Inertia request into native flat errors', function (): void {
    $app = guarded(routes: function (): void {
        Route::middleware('web')->post('/inertia/orders', function () {
            TestApp::$seen['controller'] = true;

            return 'saved';
        });
    });
    $request = TestApp::request('/inertia/orders', 'POST', [
        'content-type' => 'application/json',
        'x-inertia' => 'true',
        'x-requested-with' => 'XMLHttpRequest',
        'referer' => 'https://app.example.test/orders/new',
    ], '{"price":NaN,"lines":[{"qty":Infinity}]}');
    $response = TestApp::handle($app, $request);

    expect($response->getStatusCode())->toBe(302);
    expect(TestApp::$seen)->not->toHaveKey('controller');
    $errors = $app['session.store']->get('errors')->getBag('default')->toArray();
    expect($errors)->toBe(['price' => ['This must be a finite number.'], 'lines.0.qty' => ['This must be a finite number.']]);
});

it('still refuses an oversize Inertia body immediately', function (): void {
    $app = guarded(routes: fn () => Route::middleware('web')->post('/inertia/orders', fn () => 'saved'));
    $request = TestApp::request('/inertia/orders', 'POST', ['content-type' => 'application/json', 'x-inertia' => 'true', 'content-length' => '5000000'], '{}', $stream);
    expect(TestApp::handle($app, $request)->getStatusCode())->toBe(413);
    expect(ftell($stream))->toBe(0);
});

// --- request id -------------------------------------------------------------

it('puts the request id on every response and in Context for the logs', function (): void {
    $app = guarded(routes: fn () => Route::post('/api/orders', fn () => response()->json(['id' => Context::get('request_id')])));
    $response = TestApp::handle($app, json_request('/api/orders', '{}'));
    $rid = $response->headers->get('x-request-id');

    expect(preg_match(Rows::UUID4, (string) $rid))->toBe(1);
    expect(json_decode($response->getContent(), true)['id'])->toBe($rid);
});

it('does not let the app or the client forge the request id by default', function (): void {
    $app = guarded(routes: fn () => Route::post('/api/orders', fn () => response('x')->header('X-Request-Id', 'forged')));
    $response = TestApp::handle($app, json_request('/api/orders', '{}', ['x-request-id' => 'client-chosen']));
    expect($response->headers->get('x-request-id'))->not->toBe('forged')->not->toBe('client-chosen');
    expect($response->headers->all('x-request-id'))->toHaveCount(1);
});

// --- the HTTP-client normaliser ---------------------------------------------

it('never shows a 5xx body from an upstream, in any shape', function (): void {
    TestApp::boot(TestApp::make());
    Http::fake([
        'up.example.test/a' => Http::response(['message' => CANARY, 'trace' => [CANARY]], 500),
        'up.example.test/b' => Http::response(['message' => 'x', 'errors' => ['db' => [CANARY]]], 503, ['Retry-After' => '120']),
    ]);
    foreach (['a', 'b'] as $path) {
        $error = Http::post("https://up.example.test/{$path}")->fancyError();
        expect(json_encode($error->toArray()))->not->toContain('SQLSTATE');
        expect($error->fields)->toBe([]);
    }
    expect(Http::post('https://up.example.test/b')->fancyError()->sentence)->toBe('The service is unavailable right now. Try again in 2 minutes.');
});

it('reads Laravel validation from an upstream as fields', function (): void {
    TestApp::boot(TestApp::make());
    Http::fake(['*' => Http::response(['message' => 'x', 'errors' => ['email' => ['The email is taken.']]], 422)]);
    $error = Http::post('https://up.example.test/users')->fancyError();
    expect($error->code)->toBe('validation_failed');
    expect($error->fields)->toBe(['email' => 'The email is taken.']);
    expect((new ClientResponse(new PsrResponse(200, [], 'ok')))->fancyError())->toBeNull();
});

it('reads an upstream body only to the cap', function (): void {
    $read = 0;
    $inner = Utils::streamFor(str_repeat('a', 5_000_000));
    $counting = FnStream::decorate($inner, ['read' => function (int $length) use ($inner, &$read) {
        $chunk = $inner->read($length);
        $read += strlen($chunk);

        return $chunk;
    }]);
    $response = new ClientResponse(new PsrResponse(400, [], $counting));
    $error = ErrorNormaliser::fromResponse($response);

    expect($error->sentence)->toBe('The request could not be understood.');
    expect($read)->toBeLessThanOrEqual(65536 + 8192);
});
