<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use ParticleAcademy\HttpGuard\HttpError;
use ParticleAcademy\HttpGuard\Renderer;
use ParticleAcademy\HttpGuard\Settings;
use ParticleAcademy\HttpGuard\Tests\Support\TestApp;
use ParticleAcademy\HttpGuard\ValidationFailed;

/*
 * The public message length (CC-fancy-0063, fancy-conformance SPEC 3.1 and
 * 4.0). One setting, `limits.maxMessageLength` (default 300, refused above
 * 4000), and an optional per-error override on HttpError and on
 * ValidationFailed items, capped at the same 4000. Only the length changes:
 * control characters are still stripped, a crash is still the fixed 500 and a
 * non-public 5xx still never shows its message, at any length.
 *
 * Written BEFORE the implementation; every test here failed first.
 */

function ml_rows(int $n): string
{
    return implode(' ', array_map(fn (int $i): string => "Row {$i} has a quantity below the minimum of 1.", range(1, $n)));
}

function ml_cut(string $text, int $max): string
{
    return mb_strlen($text) <= $max ? $text : mb_substr($text, 0, $max - 1)."\u{2026}";
}

function ml_trace(): string
{
    $lines = ['Traceback (most recent call last):'];
    foreach (range(1, 12) as $i) {
        $lines[] = "  File \"/srv/app/imports/rows.php\", line {$i}, in check_row_{$i}";
    }
    $lines[] = 'SQLSTATE[42S02]: Base table or view not found: 1146 Table prod.users';

    return implode("\n", $lines);
}

/**
 * The response to an exception thrown by a route, with these limits.
 *
 * @param  array<string, mixed>  $limits
 * @return array<string, mixed>
 */
function ml_answer(Throwable $e, array $limits = [], ?callable $routes = null, string $uri = '/throw'): array
{
    $app = TestApp::make(['fancy-http-guard.limits' => $limits], $routes ?? function () use ($e): void {
        Route::post('/throw', fn () => throw $e);
    });
    $request = TestApp::request($uri, 'POST', ['content-type' => 'application/json', 'accept' => 'application/json'], '{}');
    $response = TestApp::handle($app, $request);
    $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    $body['status'] = $response->getStatusCode();
    $body['raw'] = (string) $response->getContent();

    return $body;
}

beforeEach(function (): void {
    $this->rows = ml_rows(8);
    $this->many = ml_rows(95);
});

it('has fixtures long enough to mean something', function (): void {
    expect(mb_strlen($this->rows))->toBeGreaterThan(300)->toBeLessThan(1000);
    expect(mb_strlen($this->many))->toBeGreaterThan(4000);
    expect(mb_strlen(ml_trace()))->toBeGreaterThan(300);
});

// --- the default is unchanged -------------------------------------------------

it('defaults to 300, with a bound of 4000', function (): void {
    expect(Settings::DEFAULTS['maxMessageLength'])->toBe(300);
    expect(Settings::resolve()['maxMessageLength'])->toBe(300);
    expect(Settings::MESSAGE_LENGTH_BOUND)->toBe(4000);
    $config = require dirname(__DIR__, 2).'/config/fancy-http-guard.php';
    expect($config['limits']['maxMessageLength'])->toBe(300);
});

it('still truncates a long 4xx message at 300 with no setting', function (): void {
    $body = ml_answer(new HttpError(400, $this->rows));
    expect($body['message'])->toBe(ml_cut($this->rows, 300));
    expect(mb_strlen($body['message']))->toBe(300);
});

it('still truncates an abort() message and validation items at 300 with no setting', function (): void {
    $rows = $this->rows;
    $body = ml_answer(new RuntimeException, routes: fn () => Route::post('/throw', fn () => abort(409, $rows)));
    expect($body['message'])->toBe(ml_cut($rows, 300));
    $body = ml_answer(ValidationException::withMessages(['rows' => [$rows]]));
    expect($body['errors'][0]['message'])->toBe(ml_cut($rows, 300));
});

// --- the setting ----------------------------------------------------------------

it('raises the limit for a 4xx message with the setting', function (): void {
    expect(ml_answer(new HttpError(400, $this->rows), ['maxMessageLength' => 1000])['message'])->toBe($this->rows);
});

it('raises the limit for abort() and framework validation with the setting', function (): void {
    $rows = $this->rows;
    $body = ml_answer(new RuntimeException, ['maxMessageLength' => 1000], fn () => Route::post('/throw', fn () => abort(409, $rows)));
    expect($body['message'])->toBe($rows);
    $body = ml_answer(ValidationException::withMessages(['rows' => [$rows]]), ['maxMessageLength' => 1000]);
    expect($body['errors'][0]['message'])->toBe($rows);
});

it('raises the limit for ValidationFailed items with the setting', function (): void {
    $body = ml_answer(new ValidationFailed([['field' => 'rows', 'message' => $this->rows]]), ['maxMessageLength' => 1000]);
    expect($body['errors'][0]['message'])->toBe($this->rows);
});

it('can lower the limit too', function (): void {
    expect(ml_answer(new HttpError(400, $this->rows), ['maxMessageLength' => 50])['message'])->toBe(ml_cut($this->rows, 50));
});

it('still truncates a message over the setting', function (): void {
    expect(ml_answer(new HttpError(400, $this->many), ['maxMessageLength' => 1000])['message'])->toBe(ml_cut($this->many, 1000));
});

it('accepts the setting at its bound', function (): void {
    expect(Settings::resolve(['maxMessageLength' => 4000])['maxMessageLength'])->toBe(4000);
    expect(ml_answer(new HttpError(400, $this->many), ['maxMessageLength' => 4000])['message'])->toBe(ml_cut($this->many, 4000));
});

it('refuses a bad or unbounded setting by name', function (mixed $value): void {
    Settings::resolve(['maxMessageLength' => $value]);
})->with([4001, 1_000_000_000, 0, -1, '300', true, 300.0])->throws(InvalidArgumentException::class, 'maxMessageLength');

it('names the bound when it refuses', function (): void {
    Settings::resolve(['maxMessageLength' => 4001]);
})->throws(InvalidArgumentException::class, '4000');

it('applies the setting per route', function (): void {
    $rows = $this->rows;
    $routes = function () use ($rows): void {
        Route::post('/long', fn () => throw new HttpError(400, $rows))->guard(['maxMessageLength' => 1000]);
        Route::post('/throw', fn () => throw new HttpError(400, $rows));
    };
    expect(ml_answer(new RuntimeException, routes: $routes, uri: '/long')['message'])->toBe($rows);
    expect(ml_answer(new RuntimeException, routes: $routes)['message'])->toBe(ml_cut($rows, 300));
});

// --- the per-error override -----------------------------------------------------

it('lets one public error through whole with its own maxMessageLength', function (): void {
    $body = ml_answer(HttpError::public(400, 'rows_refused', $this->rows, maxMessageLength: 1000));
    expect($body['error'])->toBe('rows_refused');
    expect($body['message'])->toBe($this->rows);
});

it('honours the override on a public 5xx and on a plain 4xx', function (): void {
    expect(ml_answer(HttpError::public(503, 'maintenance', $this->rows, maxMessageLength: 1000))['message'])->toBe($this->rows);
    expect(ml_answer(new HttpError(409, $this->rows, maxMessageLength: 1000))['message'])->toBe($this->rows);
});

it('lets an override be smaller than the setting', function (): void {
    $body = ml_answer(HttpError::public(400, 'rows_refused', $this->rows, maxMessageLength: 100), ['maxMessageLength' => 1000]);
    expect($body['message'])->toBe(ml_cut($this->rows, 100));
});

it('counts an override over the bound as the bound', function (): void {
    $body = ml_answer(HttpError::public(400, 'rows_refused', $this->many, maxMessageLength: 100_000));
    expect($body['message'])->toBe(ml_cut($this->many, 4000));
    expect(mb_strlen($body['message']))->toBe(4000);
});

it('keeps an item override to its own item', function (): void {
    $body = ml_answer(new ValidationFailed([
        ['field' => 'rows', 'error' => 'rows_refused', 'message' => $this->rows, 'maxMessageLength' => 1000],
        ['field' => 'note', 'message' => $this->rows],
    ]));
    expect($body['message'])->toBe('2 fields need attention.');
    expect($body['errors'])->toBe([
        ['error' => 'rows_refused', 'message' => $this->rows, 'field' => 'rows'],
        ['error' => 'invalid', 'message' => ml_cut($this->rows, 300), 'field' => 'note'],
    ]);
});

it('counts an item override over the bound as the bound', function (): void {
    $body = ml_answer(new ValidationFailed([['field' => 'rows', 'message' => $this->many, 'maxMessageLength' => 1_000_000_000]]));
    expect($body['errors'][0]['message'])->toBe(ml_cut($this->many, 4000));
});

it('refuses a bad override on HttpError where it is raised', function (mixed $value): void {
    new HttpError(400, 'x', maxMessageLength: $value);
})->with([0, -5])->throws(InvalidArgumentException::class, 'maxMessageLength');

it('refuses a bad override on a ValidationFailed item where it is raised', function (mixed $value): void {
    new ValidationFailed([['field' => 'rows', 'message' => 'x', 'maxMessageLength' => $value]]);
})->with([0, -5, '1000', true, 1000.0])->throws(InvalidArgumentException::class, 'maxMessageLength');

it('caps a limit handed to the renderer directly', function (): void {
    $envelope = Renderer::render(new HttpError(400, $this->many), 'r-1', 'x-request-id', 1_000_000_000);
    expect($envelope->body['message'])->toBe(ml_cut($this->many, 4000));
});

// --- no opt-out from sanitising ---------------------------------------------------

it('still strips control characters at the bound', function (): void {
    $lines = array_map(fn (int $i): string => "Row {$i} has a quantity below the minimum of 1.", range(1, 8));
    $noisy = implode("\n\t", array_slice($lines, 0, 4))."\0\u{85}".implode("\r\n", array_slice($lines, 4));
    $body = ml_answer(new HttpError(400, $noisy), ['maxMessageLength' => 4000]);
    expect($body['message'])->toBe($this->rows);
    expect(preg_match('/[\x{0}-\x{1F}\x{7F}-\x{9F}]/u', $body['message']))->toBe(0);
});

it('does not skip sanitising for a public override', function (): void {
    $body = ml_answer(HttpError::public(400, 'rows_refused', "One\n\0two", maxMessageLength: 4000));
    expect($body['message'])->toBe('One two');
});

it('still renders a crash as the fixed 500 at the bound, APP_DEBUG on', function (): void {
    $trace = ml_trace();
    $app = TestApp::make(['fancy-http-guard.limits' => ['maxMessageLength' => 4000]], fn () => Route::post('/throw', fn () => throw new RuntimeException($trace)), true);
    $response = TestApp::handle($app, TestApp::request('/throw', 'POST', ['content-type' => 'application/json', 'accept' => 'application/json'], '{}'));
    expect($response->getStatusCode())->toBe(500);
    expect($response->getContent())->not->toContain('Traceback')->not->toContain('/srv/app')->not->toContain('SQLSTATE');
    expect(json_decode((string) $response->getContent(), true)['error'])->toBe('server_error');
});

it('still never shows a non-public 5xx message at the bound', function (): void {
    $body = ml_answer(new HttpError(503, ml_trace(), maxMessageLength: 4000), ['maxMessageLength' => 4000]);
    expect($body['status'])->toBe(503);
    expect($body['message'])->toStartWith('The service is unavailable right now.');
    expect($body['raw'])->not->toContain('/srv/app')->not->toContain('Traceback');
    $trace = ml_trace();
    $body = ml_answer(new RuntimeException, ['maxMessageLength' => 4000], fn () => Route::post('/throw', fn () => abort(502, $trace)));
    expect($body['raw'])->not->toContain('/srv/app')->not->toContain('SQLSTATE');
});
