<?php

declare(strict_types=1);

namespace ParticleAcademy\HttpGuard;

use RuntimeException;

/**
 * A 422 whose items carry their own codes (`workbook_unreadable`), which
 * Laravel's ValidationException cannot express. Items: `{error?, message, field?}`.
 */
final class ValidationFailed extends RuntimeException
{
    /** @param  list<array<string, mixed>>  $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('validation failed');
    }
}
