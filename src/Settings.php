<?php

declare(strict_types=1);

namespace ParticleAcademy\HttpGuard;

use InvalidArgumentException;

/**
 * Limits: secure defaults, every one an app setting (fancy-conformance
 * suites/http/SPEC.md section 3.1). The names are the contract's, identical in
 * the Python guard and in holy-sheet's `limits` option.
 */
final class Settings
{
    public const DEFAULTS = [
        'maxBodyBytes' => 1048576,
        'maxDepth' => 32,
        'maxListLength' => 10000,
        'maxErrors' => 20,
        'finiteNumbers' => true,
        'workbook' => ['maxRows' => 100000, 'maxColumns' => 1000, 'maxCells' => 100000],
    ];

    public const PROFILES = [
        'default' => [],
        'upload' => ['maxBodyBytes' => 10485760],
    ];

    private const INTS = ['maxBodyBytes', 'maxDepth', 'maxListLength', 'maxErrors'];

    private const WORKBOOK = ['maxRows', 'maxColumns', 'maxCells'];

    /**
     * The defaults (or `$base`) overridden key by key. A bad key or value is
     * refused, naming it.
     *
     * @param  array<string, mixed>  $limits
     * @param  array<string, mixed>|null  $base
     * @return array<string, mixed>
     */
    public static function resolve(array $limits = [], ?array $base = null): array
    {
        $out = $base ?? self::DEFAULTS;
        foreach ($limits as $key => $value) {
            if (in_array($key, self::INTS, true)) {
                $out[$key] = self::positive((string) $key, $value);
            } elseif ($key === 'finiteNumbers') {
                if (! is_bool($value)) {
                    throw self::refuse('finiteNumbers must be true or false');
                }
                $out[$key] = $value;
            } elseif ($key === 'workbook') {
                if (! is_array($value)) {
                    throw self::refuse('workbook must be an array of maxRows / maxColumns / maxCells');
                }
                foreach ($value as $wk => $wv) {
                    if (! in_array($wk, self::WORKBOOK, true)) {
                        throw self::refuse("unknown workbook limit \"{$wk}\"; known: ".implode(', ', self::WORKBOOK));
                    }
                    $out['workbook'][$wk] = self::positive("workbook.{$wk}", $wv);
                }
            } else {
                throw self::refuse("unknown limit \"{$key}\"; known: ".implode(', ', [...self::INTS, 'finiteNumbers', 'workbook']));
            }
        }

        return $out;
    }

    /**
     * A named profile, or limits given inline, resolved over a base.
     *
     * @param  string|array<string, mixed>  $profileOrLimits
     * @param  array<string, array<string, mixed>>  $profiles
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    public static function forRoute(string|array $profileOrLimits, array $profiles, array $base): array
    {
        if (is_string($profileOrLimits)) {
            $all = self::PROFILES + $profiles;
            if (! array_key_exists($profileOrLimits, $all)) {
                throw self::refuse("unknown profile \"{$profileOrLimits}\"; known: ".implode(', ', array_keys($all)));
            }

            return self::resolve($profiles[$profileOrLimits] ?? self::PROFILES[$profileOrLimits], $base);
        }

        return self::resolve($profileOrLimits, $base);
    }

    /**
     * holy-sheet's `limits` option for a route (SPEC section 5). 64 bytes per
     * cell is holy-sheet's measured 55-58, rounded up; the floors keep a tiny
     * cell cap from refusing a small file's styles part. The archive cap is the
     * body cap, so a file the guard would have refused cannot be read through a
     * looser one.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, int>
     */
    public static function workbookLimits(array $settings): array
    {
        $cells = (int) $settings['workbook']['maxCells'];

        return [
            'maxRows' => (int) $settings['workbook']['maxRows'],
            'maxColumns' => (int) $settings['workbook']['maxColumns'],
            'maxCells' => $cells,
            'maxSharedStrings' => $cells,
            'maxArchiveBytes' => (int) $settings['maxBodyBytes'],
            'maxPartBytes' => max(65536, 64 * $cells),
            'maxExpandedBytes' => max(262144, 256 * $cells),
        ];
    }

    private static function positive(string $name, mixed $value): int
    {
        if (! is_int($value) || $value < 1) {
            throw self::refuse("{$name} must be a positive integer, got ".var_export($value, true));
        }

        return $value;
    }

    private static function refuse(string $why): InvalidArgumentException
    {
        return new InvalidArgumentException("fancy-http-guard: {$why}");
    }
}
