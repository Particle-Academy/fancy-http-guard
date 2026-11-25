<?php

declare(strict_types=1);

namespace ParticleAcademy\HttpGuard;

use HolySheet\Exceptions\ArchiveRefused;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * What an exception becomes (fancy-conformance suites/http/SPEC.md section 4).
 *
 * **Exception text is never echoed.** Anything unrecognised is a fixed 500, in
 * every environment, APP_DEBUG included; its detail is in the log Laravel
 * already wrote, under the request id. A deliberate 4xx keeps the app's own
 * sentence, unless Laravel made the exception out of another one (a
 * ModelNotFoundException's message names the model class). A 5xx never does,
 * unless the app raised HttpError::public().
 *
 * holy-sheet is optional: `instanceof` against a class that does not exist is
 * false and loads nothing.
 */
final class Renderer
{
    public const CODE = '/^[a-z][a-z0-9_]{0,63}$/D';

    private const DELTA = '/^[0-9]{1,10}$/D';

    /**
     * `$maxMessageLength` is the route's `maxMessageLength` (SPEC 4.0); an
     * error's own override replaces it, and neither passes 4000. Only the
     * length changes: the message is sanitised, and a crash or a non-public 5xx
     * never shows its text, whatever the length.
     */
    public static function render(Throwable $e, string $requestId, string $requestIdHeader = 'x-request-id', int $maxMessageLength = 300): Envelope
    {
        $rid = $requestId;
        $rh = $requestIdHeader;
        $route = Settings::effectiveMessageLength($maxMessageLength);

        if ($e instanceof HttpError) {
            if ($e->isPublic) {
                if ($e->error === null || preg_match(self::CODE, $e->error) !== 1) {
                    return self::crash($rid, $rh);
                }
                $retry = $e->retryAfter !== null && $e->retryAfter >= 0 ? $e->retryAfter : self::retry($e->headers);
                $text = Sentences::sanitise($e->getMessage(), Settings::effectiveMessageLength($route, $e->maxMessageLength)) ?: Sentences::statusDefaults($e->status)[1];

                return Envelope::make($e->status, $e->error, $text, $rid, $rh, $retry);
            }
            $envelope = self::http($e->status, $e->getMessage(), $e->headers, $rid, $rh, Settings::effectiveMessageLength($route, $e->maxMessageLength));
            if ($e->error !== null && preg_match(self::CODE, $e->error) === 1 && $e->status < 500 && $e->status !== 422) {
                return new Envelope($envelope->status, ['error' => $e->error] + $envelope->body, $envelope->headers);
            }

            return $envelope;
        }

        if ($e instanceof ValidationFailed) {
            $items = [];
            foreach ($e->errors as $item) {
                $code = isset($item['error']) && is_string($item['error']) && preg_match(self::CODE, $item['error']) === 1 ? $item['error'] : 'invalid';
                $max = Settings::effectiveMessageLength($route, $item['maxMessageLength'] ?? null);
                $out = ['error' => $code, 'message' => Sentences::sanitise((string) ($item['message'] ?? ''), $max) ?: Sentences::statusDefaults(422)[1]];
                if (isset($item['field']) && is_string($item['field']) && $item['field'] !== '') {
                    $out['field'] = $item['field'];
                }
                $items[] = $out;
            }

            return Envelope::validation($items ?: [['error' => 'invalid', 'message' => Sentences::statusDefaults(422)[1]]], $rid, $rh);
        }

        if ($e instanceof ValidationException) {
            $items = [];
            foreach ($e->errors() as $field => $messages) {
                $first = is_array($messages) ? (string) (array_values($messages)[0] ?? '') : (string) $messages;
                $items[] = ['error' => 'invalid', 'message' => Sentences::sanitise($first, $route) ?: Sentences::statusDefaults(422)[1], 'field' => (string) $field];
            }

            return Envelope::validation($items ?: [['error' => 'invalid', 'message' => Sentences::statusDefaults(422)[1]]], $rid, $rh);
        }

        if ($e instanceof AuthenticationException) {
            return self::http(401, '', [], $rid, $rh, $route);
        }

        if ($e instanceof HttpExceptionInterface) {
            // A message Laravel made from ANOTHER exception (ModelNotFound,
            // TokenMismatch, a request-parsing error) is the framework's text,
            // not the app's sentence. A policy's denial message is the app's.
            $previous = $e instanceof Throwable ? $e->getPrevious() : null;
            $own = $previous === null || $previous instanceof AuthorizationException;

            return self::http($e->getStatusCode(), $own && $e instanceof Throwable ? $e->getMessage() : '', $e->getHeaders(), $rid, $rh, $route);
        }

        if ($e instanceof ArchiveRefused && in_array($e->reason, ['archive_limit', 'read_limit'], true)
            && is_string($e->limit) && is_int($e->max) && is_int($e->actual)) {
            $limit = ['name' => $e->limit, 'max' => $e->max, 'actual' => $e->actual];

            return Envelope::make(413, 'workbook_too_large', Sentences::workbook($limit), $rid, $rh, null, ['limit' => $limit]);
        }

        return self::crash($rid, $rh);
    }

    public static function crash(string $rid, string $rh = 'x-request-id'): Envelope
    {
        return Envelope::make(500, 'server_error', Sentences::serverSentence(500, null, $rid), $rid, $rh);
    }

    /** @param  array<string, mixed>  $headers */
    private static function http(int $status, string $message, array $headers, string $rid, string $rh, int $max = 300): Envelope
    {
        [$code, $fallback] = Sentences::statusDefaults($status);
        $retry = self::retry($headers);
        if ($status >= 500) {
            return Envelope::make($status, $code, Sentences::serverSentence($status, $retry, $rid), $rid, $rh, $retry);
        }
        if ($status === 429) {
            return Envelope::make(429, $code, Sentences::rateLimited($retry), $rid, $rh, $retry);
        }
        $text = Sentences::sanitise($message, $max);
        if ($status === 422) {
            return Envelope::validation([['error' => 'invalid', 'message' => $text ?: $fallback]], $rid, $rh);
        }

        return Envelope::make($status, $code, $text ?: $fallback, $rid, $rh, $retry);
    }

    /** @param  array<string, mixed>  $headers */
    private static function retry(array $headers): ?int
    {
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'retry-after') {
                $value = trim(is_array($value) ? (string) ($value[0] ?? '') : (string) $value);

                return preg_match(self::DELTA, $value) === 1 ? (int) $value : null;
            }
        }

        return null;
    }
}
