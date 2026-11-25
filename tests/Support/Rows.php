<?php

declare(strict_types=1);

namespace ParticleAcademy\HttpGuard\Tests\Support;

use HolySheet\Exceptions\ArchiveRefused;
use HolySheet\Exceptions\ReadLimitExceeded;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use ParticleAcademy\Conformance\Conformance;
use ParticleAcademy\HttpGuard\Client\ErrorNormaliser;
use ParticleAcademy\HttpGuard\FancyHttpGuard;
use ParticleAcademy\HttpGuard\HttpError;
use ParticleAcademy\HttpGuard\Renderer;
use ParticleAcademy\HttpGuard\Sentences;
use ParticleAcademy\HttpGuard\Settings;
use ParticleAcademy\HttpGuard\ValidationFailed;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Turns a fancy-conformance http row into a call through the real kernel, and
 * the result back into the row's shape. The runner rules (request-guards
 * manifest): "$generated" stands for a generated lowercase UUID v4, the same in
 * header and body; bodyRead is measured from the request stream; a refusal
 * never reaches the application, and a pass hands it the identical bytes.
 */
final class Rows
{
    /**
     * The fancy-conformance tag these tests pin. CI checks out `ref: v<this>`;
     * PinTest fails if the two disagree or the checkout is another version.
     */
    public const PINNED_SUITE_VERSION = '0.62.0';

    public const GEN = '$generated';

    public const UUID4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private const FIXED_ID = '4f8c2a9e-1b7d-4c3e-9a5f-2d6b8e0c1a73';

    /**
     * The checkout: FANCY_CONFORMANCE_ROOT in CI, else the envelope's sibling.
     * Read from a checkout rather than Packagist: the fixtures are the suite's
     * internal alignment tool, and the Python guard reads them the same way. A
     * missing checkout is a FAILURE, never a skip.
     */
    public static function root(): string
    {
        $root = getenv('FANCY_CONFORMANCE_ROOT') ?: dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'fancy-conformance';
        if (! is_file($root.'/php/src/Conformance.php') || ! is_file($root.'/VERSION')) {
            throw new RuntimeException("No fancy-conformance checkout at {$root}. Set FANCY_CONFORMANCE_ROOT, or check it out beside this repository at v".self::PINNED_SUITE_VERSION.'.');
        }
        require_once $root.'/php/src/Conformance.php';

        return (string) realpath($root);
    }

    /** @return array<string, mixed> */
    public static function envelopeResult(Response $response, string $ridHeader, ?string $inbound): array
    {
        $rid = $response->headers->get($ridHeader);
        expect($rid)->not->toBeNull('every response carries the request id');
        $generated = $rid !== $inbound;
        if ($generated) {
            expect(preg_match(self::UUID4, (string) $rid))->toBe(1, "a generated id is a lowercase UUID v4, got {$rid}");
        }
        expect($response->headers->get('content-type'))->toBe('application/json');
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        expect($body['requestId'] ?? null)->toBe($rid);
        $headers = ['content-type' => 'application/json', $ridHeader => $rid];
        if ($response->headers->has('retry-after')) {
            $headers['retry-after'] = $response->headers->get('retry-after');
        }
        $out = ['status' => $response->getStatusCode(), 'headers' => $headers, 'body' => $body];

        return $generated ? self::swap($out, (string) $rid) : $out;
    }

    /** @param  array<string, mixed>  $value */
    private static function swap(array $value, string $rid): array
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return json_decode(str_replace($rid, self::GEN, $json), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function bytes(mixed $body, string $suite): string
    {
        if (is_string($body)) {
            return $body;
        }
        if (is_array($body) && isset($body['hex'])) {
            return (string) hex2bin($body['hex']);
        }

        return Conformance::fixtureBytes($suite, $body);
    }

    /** @param  array<string, mixed>  $input */
    public static function guard(array $input): array
    {
        $config = $input['config'] ?? [];
        $ridHeader = strtolower($config['requestIdHeader'] ?? 'x-request-id');
        $app = TestApp::make([
            'fancy-http-guard.limits' => $input['limits'] ?? [],
            'fancy-http-guard.request_id_header' => $ridHeader,
            'fancy-http-guard.trust_incoming_request_id' => $config['trustIncomingRequestId'] ?? false,
        ], static function (): void {
            Route::post('/probe', static function (\Illuminate\Http\Request $request) {
                TestApp::$seen['body'] = $request->getContent();

                return response('ok', 200);
            });
        });
        $data = self::bytes($input['body'], 'http/request-guards');
        $headers = $input['headers'];
        $request = TestApp::request('/probe', 'POST', $headers, $data, $stream);
        $response = TestApp::handle($app, $request);
        $inbound = $headers[$ridHeader] ?? null;

        if ($response->getStatusCode() === 200 && array_key_exists('body', TestApp::$seen)) {
            expect(TestApp::$seen['body'])->toBe($data, 'a pass hands the application the identical bytes');
            $rid = (string) $response->headers->get($ridHeader);
            if ($rid !== $inbound) {
                expect(preg_match(self::UUID4, $rid))->toBe(1);

                return ['pass' => true, 'requestId' => self::GEN];
            }

            return ['pass' => true, 'requestId' => $rid];
        }
        expect(TestApp::$seen)->not->toHaveKey('body', 'a refusal never reaches the application');
        $out = self::envelopeResult($response, $ridHeader, $inbound);
        if (! in_array($out['body']['error'], ['body_too_large', 'bad_request'], true)) {
            return $out;
        }
        $cap = Settings::resolve($input['limits'] ?? [])['maxBodyBytes'];
        $pulled = ftell($stream);
        $out['bodyRead'] = $pulled === 0 ? 'none' : ($pulled <= $cap + 1 + 64 ? 'bounded' : 'full');

        return $out;
    }

    /** @param  array<string, mixed>  $t */
    public static function exception(array $t): Throwable
    {
        return match ($t['kind']) {
            'crash' => new RuntimeException($t['message']),
            'validation' => ValidationException::withMessages(
                collect($t['errors'])->mapWithKeys(fn (array $e) => [$e['field'] => [$e['message']]])->all()
            ),
            'public' => HttpError::public($t['status'], $t['error'], $t['message'], $t['retryAfter'] ?? null, $t['maxMessageLength'] ?? null),
            'failed' => new ValidationFailed($t['errors']),
            'workbook' => in_array($t['limit'], ['maxRows', 'maxColumns', 'maxCells', 'maxSharedStrings'], true)
                ? new ReadLimitExceeded($t['limit'], $t['max'], $t['actual'])
                : ArchiveRefused::overLimit($t['limit'], $t['max'], $t['actual']),
            default => throw new RuntimeException('unknown kind '.$t['kind']),
        };
    }

    /** @param  array<string, mixed>  $input */
    public static function render(array $input): array
    {
        $t = $input['throw'];
        $config = $input['config'] ?? [];
        $ridHeader = strtolower($config['requestIdHeader'] ?? 'x-request-id');
        $app = TestApp::make([
            'fancy-http-guard.limits' => $input['limits'] ?? [],
            'fancy-http-guard.request_id_header' => $ridHeader,
            'fancy-http-guard.trust_incoming_request_id' => $config['trustIncomingRequestId'] ?? false,
        ], static function () use ($t): void {
            Route::post('/render', static function () use ($t) {
                if ($t['kind'] === 'response') {
                    return response('untouched', $t['status'], $t['headers'] ?? []);
                }
                if ($t['kind'] === 'http') {
                    abort($t['status'], $t['message'] ?? '', $t['headers'] ?? []);
                }
                throw self::exception($t);
            });
        });
        $headers = ['content-type' => 'application/json', 'accept' => 'application/json'] + ($input['headers'] ?? []);
        $response = TestApp::handle($app, TestApp::request('/render', 'POST', $headers, '{}'));
        if ($t['kind'] === 'crash') {
            $everything = $response->getContent().json_encode($response->headers->all());
            expect(str_contains($everything, $t['message']))->toBeFalse("a crash's text reached the response");
        }
        if ($response->getContent() === 'untouched') {
            expect($response->headers->has($ridHeader))->toBeTrue('even a passthrough carries the request id');

            return ['passthrough' => true, 'status' => $response->getStatusCode()];
        }

        return self::envelopeResult($response, $ridHeader, $input['headers'][$ridHeader] ?? null);
    }

    /** @param  array<string, mixed>  $input */
    public static function workbook(array $input): array
    {
        $data = Conformance::fixtureBytes('http/request-guards', $input['file']);
        try {
            FancyHttpGuard::readWorkbook($data, $input['limits'] ?? [], $input['field']);
        } catch (Throwable $e) {
            $envelope = Renderer::render($e, self::FIXED_ID);
            $out = ['status' => $envelope->status, 'headers' => ['content-type' => 'application/json', 'x-request-id' => self::GEN], 'body' => $envelope->body];

            return self::swap($out, self::FIXED_ID);
        }

        return ['pass' => true];
    }

    /** @param  array<string, mixed>  $case */
    public static function errorEnvelope(array $case): mixed
    {
        $in = $case['input'];

        return match ($case['fn']) {
            'formatBytes' => Sentences::formatBytes($in['n']),
            'formatDuration' => Sentences::formatDuration($in['s']),
            'sanitise' => isset($in['max']) ? Sentences::sanitise($in['text'], $in['max']) : Sentences::sanitise($in['text']),
            'normalise' => ErrorNormaliser::normalise($in)?->toArray(),
        };
    }
}
