<?php

declare(strict_types=1);

namespace ParticleAcademy\HttpGuard;

use Illuminate\Http\Request;

/**
 * Inspects a request's raw body before anything parses it
 * (fancy-conformance suites/http/SPEC.md section 3.2). Returns the refusal, or
 * null to let the request through.
 *
 * 1. Content-Length: malformed is a 400; over the cap is a 413 and NOT ONE body
 *    byte is read. An empty value is absent (PHP-FPM sends one on every GET).
 * 2. A bounded read through `getContent(true)`: at most `maxBodyBytes + 1`
 *    bytes are pulled from php://input, which catches a missing or lying length.
 * 3. JSON (and a body with no Content-Type) is scanned: depth, members, finite
 *    numbers.
 *
 * `multipart/form-data` and `application/x-www-form-urlencoded` are parsed by
 * PHP itself into $_FILES/$_POST before any PHP code runs, governed by
 * `post_max_size`; for those only step 1 applies, and the README says so.
 */
final class Guard
{
    /** @param  array<string, mixed>  $limits */
    public static function inspect(Request $request, array $limits, string $rid, string $rh = 'x-request-id'): ?Envelope
    {
        $cap = (int) $limits['maxBodyBytes'];
        $tooLarge = static function (?int $actual) use ($cap, $rid, $rh): Envelope {
            $limit = ['name' => 'maxBodyBytes', 'max' => $cap];
            if ($actual !== null) {
                $limit['actual'] = $actual;
            }

            return Envelope::make(413, 'body_too_large', Sentences::bodyTooLarge($cap, $actual), $rid, $rh, null, ['limit' => $limit]);
        };

        $length = $request->server->get('CONTENT_LENGTH', $request->headers->get('content-length'));
        if ($length !== null && $length !== '') {
            $length = (string) $length;
            if (preg_match('/^[0-9]+$/D', $length) !== 1) {
                return Envelope::make(400, 'bad_request', Sentences::statusDefaults(400)[1], $rid, $rh);
            }
            if (strlen($length) > 15) {
                return $tooLarge(null);
            }
            if ((int) $length > $cap) {
                return $tooLarge((int) $length);
            }
        }

        $type = $request->headers->get('content-type');
        $media = $type === null ? null : strtolower(trim(explode(';', $type, 2)[0]));
        if ($media === 'multipart/form-data' || $media === 'application/x-www-form-urlencoded') {
            return null;
        }

        $stream = $request->getContent(true);
        $data = is_resource($stream) ? (string) stream_get_contents($stream, $cap + 1) : '';
        if (strlen($data) > $cap) {
            return $tooLarge(null);
        }

        $json = $media === null || $media === 'application/json' || str_ends_with($media, '+json');
        if (! $json || $data === '') {
            return null;
        }
        if (str_starts_with($data, "\xEF\xBB\xBF") || ! mb_check_encoding($data, 'UTF-8')) {
            return Envelope::make(400, 'malformed_body', Sentences::MALFORMED, $rid, $rh);
        }
        $result = Scanner::scan($data, $limits);
        if ($result['fault']) {
            return Envelope::make(400, 'malformed_body', Sentences::MALFORMED, $rid, $rh);
        }
        if ($result['items'] !== []) {
            return Envelope::validation($result['items'], $rid, $rh);
        }

        return null;
    }
}
