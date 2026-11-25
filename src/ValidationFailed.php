<?php

declare(strict_types=1);

namespace ParticleAcademy\HttpGuard;

use RuntimeException;

/**
 * A 422 whose items carry their own codes (`workbook_unreadable`), which
 * Laravel's ValidationException cannot express. Items: `{error?, message, field?,
 * maxMessageLength?}`; an item's `maxMessageLength` (capped at 4000) applies to
 * that item alone.
 */
final class ValidationFailed extends RuntimeException
{
    /** @param  list<array<string, mixed>>  $errors */
    public function __construct(public readonly array $errors)
    {
        foreach ($errors as $item) {
            if (is_array($item) && array_key_exists('maxMessageLength', $item)) {
                Settings::messageLengthOverride($item['maxMessageLength']);
            }
        }
        parent::__construct('validation failed');
    }
}
