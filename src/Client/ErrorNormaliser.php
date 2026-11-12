<?php

declare(strict_types=1);

namespace ParticleAcademy\HttpGuard\Client;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\Response;
use ParticleAcademy\HttpGuard\Renderer;
use ParticleAcademy\HttpGuard\Sentences;
use stdClass;
use Throwable;

/**
 * Laravel's HTTP-client side of the contract (fancy-conformance
 * suites/http/SPEC.md section 6): any error response from an upstream, as one
 * sentence. Runs the same `http/error-envelope` rows as the TypeScript
 * normaliser.
 *
 *     $error = Http::post($url, $data)->fancyError();   // null on a 2xx/3xx
 *
 * A 5xx never shows the upstream's text, in any shape, fields included. The
 * body is read only to `maxBodyBytes`, and JSON nested over `maxDepth` (or
 * carrying a lone surrogate) is unrecognised.
 */
final class ErrorNormaliser
{
    public const DEFAULTS = ['maxBodyBytes' => 65536, 'maxDepth' => 64, 'maxMessageLength' => 300, 'requestIdHeader' => 'x-request-id'];

    private const RID = '/^[A-Za-z0-9._:-]{1,128}$/D';

    private const IMF = '/^(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun), [0-9]{2} (?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) [0-9]{4} [0-9]{2}:[0-9]{2}:[0-9]{2} GMT$/D';

    /**
     * @param  array{status: int, headers?: array<string, string|list<string>>, body?: ?string, now?: string}  $input
     * @param  array<string, mixed>  $options
     */
    public static function normalise(array $input, array $options = []): ?NormalisedError
    {
        $o = $options + self::DEFAULTS;
        $status = (int) $input['status'];
        if ($status >= 100 && $status < 400) {
            return null;
        }
        $headers = [];
        foreach ($input['headers'] ?? [] as $name => $value) {
            $headers[strtolower((string) $name)] = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
        }
        $serverSide = $status === 0 || $status >= 500;
        $clientSide = $status >= 400 && $status < 500;
        $b = self::read($input['body'] ?? null, $o);
        $clean = fn (string $s): string => Sentences::sanitise($s, (int) $o['maxMessageLength']);

        $requestId = null;
        $hid = $headers[strtolower((string) $o['requestIdHeader'])] ?? null;
        if ($hid !== null && preg_match(self::RID, $hid) === 1) {
            $requestId = $hid;
        } elseif ($b !== null && is_string($b->requestId ?? null) && preg_match(self::RID, $b->requestId) === 1) {
            $requestId = $b->requestId;
        }

        $retryAfter = self::retryAfter($headers['retry-after'] ?? null, $input['now'] ?? null);
        if ($retryAfter === null && $b !== null && is_int($b->retryAfter ?? null) && $b->retryAfter >= 0 && $b->retryAfter <= 2147483647) {
            $retryAfter = $b->retryAfter;
        }

        $code = Sentences::statusDefaults($status)[0];
        $serverMessage = null;
        $items = 0;
        $firstItem = null;
        $limit = null;
        $fields = [];
        $put = function (string $field, string $message) use (&$fields, $clean): void {
            if (! array_key_exists($field, $fields)) {
                $fields[$field] = $clean($message);
            }
        };

        if ($b !== null && is_string($b->error ?? null) && preg_match(Renderer::CODE, $b->error) === 1 && is_string($b->message ?? null)) {
            $code = $b->error;
            $serverMessage = $b->message;
            $limit = self::limit($b->limit ?? null);
            if (is_array($b->errors ?? null)) {
                foreach ($b->errors as $it) {
                    if (! $it instanceof stdClass || ! is_string($it->message ?? null)) {
                        continue;
                    }
                    $items++;
                    $firstItem ??= $it->message;
                    if (is_string($it->field ?? null)) {
                        $put($it->field, $it->message);
                    }
                }
            }
            if (is_string($b->field ?? null)) {
                $put($b->field, $b->message);
            }
        } elseif ($b !== null && is_array($b->detail ?? null) && $b->detail !== [] && self::fastApiList($b->detail)) {
            if ($clientSide) {
                $code = 'validation_failed';
            }
            foreach ($b->detail as $d) {
                $items++;
                $firstItem ??= $d->msg;
                $loc = $d->loc;
                if (isset($loc[0]) && in_array($loc[0], ['body', 'query', 'path', 'header', 'cookie'], true)) {
                    array_shift($loc);
                }
                $path = implode('.', array_map(fn ($p) => is_string($p) ? $p : json_encode($p), $loc));
                if ($path !== '') {
                    $put($path, $d->msg);
                }
            }
        } elseif ($b !== null && is_string($b->detail ?? null)) {
            $serverMessage = $b->detail;
        } elseif ($b !== null && ($b->errors ?? null) instanceof stdClass && self::laravelMap($b->errors)) {
            if ($clientSide) {
                $code = 'validation_failed';
            }
            foreach (get_object_vars($b->errors) as $field => $v) {
                $first = is_string($v) ? $v : (array_values(array_filter($v, 'is_string'))[0] ?? null);
                if ($first === null) {
                    continue;
                }
                $items++;
                $firstItem ??= $first;
                $put((string) $field, $first);
            }
            if (is_string($b->message ?? null)) {
                $serverMessage = $b->message;
            }
        } elseif ($b !== null && is_string($b->message ?? null)) {
            $serverMessage = $b->message;
        }

        if ($serverSide) {
            $sentence = Sentences::serverSentence($status, $retryAfter, $requestId);
        } elseif ($code === 'validation_failed' && $items > 0) {
            $sentence = $items === 1 ? ($fields !== [] ? (string) reset($fields) : $clean((string) $firstItem)) : "{$items} fields need attention.";
            $sentence = $sentence !== '' ? $sentence : Sentences::statusDefaults(422)[1];
        } elseif ($code === 'rate_limited' || $status === 429) {
            $sentence = Sentences::rateLimited($retryAfter);
        } elseif ($code === 'body_too_large' && $limit !== null) {
            $sentence = Sentences::bodyTooLarge($limit['max'], $limit['actual'] ?? null);
        } elseif ($code === 'workbook_too_large' && $limit !== null) {
            $sentence = Sentences::workbook($limit);
        } else {
            $s = $serverMessage === null ? '' : $clean($serverMessage);
            $sentence = $s !== '' ? $s : Sentences::statusDefaults($status)[1];
        }

        return new NormalisedError($status, $code, $sentence, $serverSide ? [] : $fields, $retryAfter, $requestId);
    }

    /**
     * An HTTP-client response. The body is read from the PSR stream only to
     * `maxBodyBytes`, so a streamed (`withOptions(['stream' => true])`) answer
     * from an untrusted upstream is never read whole.
     *
     * @param  array<string, mixed>  $options
     */
    public static function fromResponse(Response $response, array $options = []): ?NormalisedError
    {
        $status = $response->status();
        if ($status >= 100 && $status < 400) {
            return null;
        }
        $cap = (int) (($options + self::DEFAULTS)['maxBodyBytes']);
        $body = null;
        try {
            $stream = $response->toPsrResponse()->getBody();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $data = '';
            while (! $stream->eof() && strlen($data) <= $cap) {
                $chunk = $stream->read(8192);
                if ($chunk === '') {
                    break;
                }
                $data .= $chunk;
            }
            $body = strlen($data) > $cap ? null : $data;
        } catch (Throwable) {
            $body = null; // a body that failed mid-read is not one to trust
        }

        return self::normalise(['status' => $status, 'headers' => $response->headers(), 'body' => $body], $options);
    }

    /** @param  array<string, mixed>  $o */
    private static function read(?string $body, array $o): ?stdClass
    {
        if ($body === null || $body === '' || strlen($body) > (int) $o['maxBodyBytes']) {
            return null;
        }
        try {
            // json_decode's depth counts one more than the containers (a scalar
            // inside N containers needs depth N + 1), so the contract's
            // "nesting of 64" is depth 65. Rows depth-64 / depth-65 pin the edge.
            // A lone surrogate or invalid UTF-8 throws.
            $v = json_decode($body, false, (int) $o['maxDepth'] + 1, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (Throwable) {
            return null;
        }

        return $v instanceof stdClass ? $v : null;
    }

    /** @return array{name: string, max: int, actual?: int}|null */
    private static function limit(mixed $l): ?array
    {
        if (! $l instanceof stdClass || ! is_string($l->name ?? null) || ! is_int($l->max ?? null) || $l->max < 0) {
            return null;
        }
        $out = ['name' => $l->name, 'max' => $l->max];
        if (property_exists($l, 'actual')) {
            if (! is_int($l->actual) || $l->actual < 0) {
                return null;
            }
            $out['actual'] = $l->actual;
        }

        return $out;
    }

    /** @param  list<mixed>  $detail */
    private static function fastApiList(array $detail): bool
    {
        foreach ($detail as $d) {
            if (! $d instanceof stdClass || ! is_array($d->loc ?? null) || ! is_string($d->msg ?? null)) {
                return false;
            }
        }

        return true;
    }

    private static function laravelMap(stdClass $errors): bool
    {
        foreach (get_object_vars($errors) as $v) {
            if (! is_string($v) && ! is_array($v)) {
                return false;
            }
        }

        return true;
    }

    private static function retryAfter(?string $header, ?string $now): ?int
    {
        if ($header === null || strlen($header) > 64) {
            return null;
        }
        $t = trim($header);
        if (preg_match('/^[0-9]{1,10}$/D', $t) === 1) {
            return (int) $t;
        }
        if (preg_match(self::IMF, $t) !== 1 || $now === null) {
            return null;
        }
        $at = DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $t, new DateTimeZone('UTC'));
        try {
            $clock = new DateTimeImmutable($now);
        } catch (Throwable) {
            return null;
        }
        if ($at === false) {
            return null;
        }
        $ms = ((float) $at->format('U.u') - (float) $clock->format('U.u')) * 1000;

        return max(0, (int) ceil($ms / 1000));
    }
}
