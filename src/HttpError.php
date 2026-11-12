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
    ) {
        parent::__construct($message);
    }

    public static function public(int $status, string $error, string $message, ?int $retryAfter = null): self
    {
        return new self($status, $message, $error, true, $retryAfter);
    }
}
