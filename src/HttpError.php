<?php

declare(strict_types=1);

namespace ParticleAcademy\HttpGuard;

use RuntimeException;

/**
 * An HTTP error the app raises on purpose.
 *
 * Laravel's own `abort(410, 'This invite has expired.')` already works: on a
 * 4xx the message is the app's sentence. On a 5xx it is NEVER shown, because a
 * 5xx message is where connection strings and hostnames end up. This class is
 * the one way to declare a 5xx sentence public:
 *
 *     throw HttpError::public(503, 'maintenance', 'Back at 14:00 UTC.', retryAfter: 600);
 *
 * A code that is not snake_case is a programming error, rendered as a 500.
 *
 * `maxMessageLength` lets THIS error's message be longer (or shorter) than the
 * route's `limits.maxMessageLength`, for one long data-built refusal:
 *
 *     throw HttpError::public(422, 'rows_refused', $sentence, maxMessageLength: 2000);
 *
 * It is capped at 4000 code points, and the message is sanitised as always. It
 * has no effect where the message is never shown (a non-public 5xx).
 */
final class HttpError extends RuntimeException
{
    /** @param  array<string, string>  $headers */
    public function __construct(
        public readonly int $status,
        string $message = '',
        public readonly ?string $error = null,
        public readonly bool $isPublic = false,
        public readonly ?int $retryAfter = null,
        public readonly array $headers = [],
        public readonly ?int $maxMessageLength = null,
    ) {
        Settings::messageLengthOverride($maxMessageLength);
        parent::__construct($message);
    }

    public static function public(int $status, string $error, string $message, ?int $retryAfter = null, ?int $maxMessageLength = null): self
    {
        return new self($status, $message, $error, true, $retryAfter, maxMessageLength: $maxMessageLength);
    }
}
