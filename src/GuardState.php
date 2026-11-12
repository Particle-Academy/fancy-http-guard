<?php

declare(strict_types=1);

namespace ParticleAcademy\HttpGuard;

/** What a controller sees at `$request->attributes->get('fancy_guard')`. */
final class GuardState
{
    /** @var array<string, int> holy-sheet's `limits` option for this route */
    public readonly array $workbookLimits;

    /** @param  array<string, mixed>  $limits */
    public function __construct(public readonly string $requestId, public readonly array $limits)
    {
        $this->workbookLimits = Settings::workbookLimits($limits);
    }

    /** @return array<string, mixed> holy-sheet's read() schema */
    public function readWorkbook(string $bytes, string $field = 'file'): array
    {
        return FancyHttpGuard::readWorkbook($bytes, $this->limits, $field, resolved: true);
    }
}
