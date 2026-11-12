<?php

declare(strict_types=1);

namespace ParticleAcademy\HttpGuard;

/**
 * The shared sentences (fancy-conformance suites/http/SPEC.md section 2).
 * The TypeScript normaliser and the Python guard write and read the same ones,
 * pinned by the same rows. Change them in the spec first.
 */
final class Sentences
{
    public const STATUS = [
        0 => ['network_error', 'We could not reach the server. Check your connection and try again.'],
        400 => ['bad_request', 'The request could not be understood.'],
        401 => ['unauthenticated', 'Please sign in to continue.'],
        403 => ['forbidden', 'You do not have permission to do that.'],
        404 => ['not_found', 'That could not be found.'],
        405 => ['method_not_allowed', 'That action is not allowed here.'],
        408 => ['request_timeout', 'The request took too long. Please try again.'],
        409 => ['conflict', 'This conflicts with a change made elsewhere. Reload and try again.'],
        410 => ['gone', 'This is no longer available.'],
        413 => ['body_too_large', 'This request is too large for the server.'],
        415 => ['unsupported_media_type', 'That type of content is not accepted here.'],
        422 => ['validation_failed', 'Some fields need attention.'],
        429 => ['rate_limited', 'Too many requests.'],
        500 => ['server_error', 'Something went wrong on our side.'],
        502 => ['upstream_error', 'A service this depends on did not respond.'],
        503 => ['unavailable', 'The service is unavailable right now.'],
        504 => ['upstream_error', 'A service this depends on did not respond.'],
    ];

    public const NOT_FINITE = 'This must be a finite number.';

    public const MALFORMED = 'The request body is not valid JSON.';

    public const UNREADABLE_WORKBOOK = 'This file could not be read as a spreadsheet.';

    /** @return array{0: string, 1: string} code and default sentence */
    public static function statusDefaults(int $status): array
    {
        return self::STATUS[$status] ?? ($status >= 500 ? self::STATUS[500] : ['client_error', 'The request could not be completed.']);
    }

    public static function formatCount(int $n): string
    {
        return number_format($n);
    }

    /**
     * A byte count for a person. Tenths are rounded in INTEGER arithmetic, half
     * up, so every runtime agrees on an exact half.
     */
    public static function formatBytes(int $n): string
    {
        if ($n < 1024) {
            return $n === 1 ? '1 byte' : self::formatCount($n).' bytes';
        }
        foreach ([[1073741824, 'GB'], [1048576, 'MB'], [1024, 'KB']] as [$unit, $name]) {
            if ($unit <= $n) {
                $tenths = intdiv($n * 10 + intdiv($unit, 2), $unit);
                $frac = $tenths % 10;

                return self::formatCount(intdiv($tenths, 10)).($frac !== 0 ? '.'.$frac : '').' '.$name;
            }
        }

        return self::formatCount($n).' bytes';
    }

    /** The retry clause, without its leading space. Minutes and hours round UP. */
    public static function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return 'Try again now.';
        }
        $unit = fn (int $n, string $one): string => self::formatCount($n).' '.$one.($n === 1 ? '' : 's');
        if ($seconds < 60) {
            return 'Try again in '.$unit($seconds, 'second').'.';
        }
        if ($seconds < 3600) {
            return 'Try again in '.$unit(intdiv($seconds + 59, 60), 'minute').'.';
        }

        return 'Try again in '.$unit(intdiv($seconds + 3599, 3600), 'hour').'.';
    }

    /**
     * Controls become spaces, runs collapse, the ends trim, and anything over
     * `$max` CODE POINTS keeps `$max - 1` and gains an ellipsis. Invalid UTF-8
     * is scrubbed first, so an exception message cannot carry raw bytes out.
     */
    public static function sanitise(string $text, int $max = 300): string
    {
        $points = mb_str_split(mb_scrub($text, 'UTF-8'), 1, 'UTF-8');
        $out = [];
        $space = true;
        foreach ($points as $ch) {
            $cp = mb_ord($ch, 'UTF-8');
            if ($cp === 0x20 || $cp <= 0x1F || ($cp >= 0x7F && $cp <= 0x9F)) {
                if (! $space) {
                    $out[] = ' ';
                }
                $space = true;
            } else {
                $out[] = $ch;
                $space = false;
            }
        }
        if ($out !== [] && end($out) === ' ') {
            array_pop($out);
        }
        if (count($out) > $max) {
            return implode('', array_slice($out, 0, max(0, $max - 1)))."\u{2026}";
        }

        return implode('', $out);
    }

    public static function rateLimited(?int $retryAfter): string
    {
        return 'Too many requests. '.($retryAfter === null ? 'Try again shortly.' : self::formatDuration($retryAfter));
    }

    /** Every 5xx sentence. Nothing an exception said reaches it. */
    public static function serverSentence(int $status, ?int $retryAfter, ?string $requestId): string
    {
        $s = self::statusDefaults($status)[1];
        if ($retryAfter !== null) {
            $s .= ' '.self::formatDuration($retryAfter);
        }
        if ($requestId !== null && $status !== 0) {
            $s .= " If it keeps happening, quote reference {$requestId}.";
        }

        return $s;
    }

    public static function bodyTooLarge(int $max, ?int $actual): string
    {
        return $actual === null
            ? "This request is over this endpoint's limit of ".self::formatBytes($max).'.'
            : 'This request is '.self::formatBytes($actual).", over this endpoint's limit of ".self::formatBytes($max).'.';
    }

    /** @param  array{name?: mixed, max?: mixed, actual?: mixed}  $limit */
    public static function workbook(array $limit): string
    {
        $name = $limit['name'] ?? null;
        $max = $limit['max'] ?? null;
        $actual = $limit['actual'] ?? null;
        if ($name === 'maxCompressionRatio') {
            return 'This spreadsheet is compressed too tightly to open safely.';
        }
        if (! is_int($actual) || ! is_int($max)) {
            return 'This spreadsheet is too large to open here.';
        }
        $c = self::formatCount(...);
        $b = self::formatBytes(...);

        return match ($name) {
            'maxRows' => "This spreadsheet has {$c($actual)} rows, over the limit of {$c($max)}.",
            'maxColumns' => "This spreadsheet has {$c($actual)} columns, over the limit of {$c($max)}.",
            'maxCells' => "This spreadsheet has at least {$c($actual)} cells, over the limit of {$c($max)}.",
            'maxSharedStrings' => "This spreadsheet has {$c($actual)} shared strings, over the limit of {$c($max)}.",
            'maxEntries' => "This spreadsheet has {$c($actual)} parts, over the limit of {$c($max)}.",
            'maxArchiveBytes' => "This spreadsheet is {$b($actual)}, over the limit of {$b($max)}.",
            'maxPartBytes', 'maxExpandedBytes' => "This spreadsheet needs at least {$b($actual)} to open, over the limit of {$b($max)}.",
            default => 'This spreadsheet is too large to open here.',
        };
    }
}
