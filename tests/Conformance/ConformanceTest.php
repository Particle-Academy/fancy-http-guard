<?php

declare(strict_types=1);

use ParticleAcademy\Conformance\Conformance;
use ParticleAcademy\HttpGuard\Settings;
use ParticleAcademy\HttpGuard\Tests\Support\Rows;

/*
 * The shared tables: fancy-conformance http/request-guards (every row, through
 * the real HTTP kernel) and http/error-envelope (the sentence helpers AND the
 * HTTP-client normaliser, which runs the same rows as the TypeScript one).
 *
 * The summary is printed unconditionally, skips included.
 */

beforeEach(fn () => Rows::root());

it('runs every http/request-guards row', function (): void {
    $summary = Conformance::runTable('http/request-guards', function (array $c) {
        return match ($c['fn']) {
            'guard' => Rows::guard($c['input']),
            'render' => Rows::render($c['input']),
            'workbookLimits' => Settings::workbookLimits(Settings::resolve($c['input']['limits'])),
            'workbook' => Rows::workbook($c['input']),
        };
    });
    fwrite(STDOUT, "\n".Conformance::formatSummary($summary)."\n");

    expect($summary['failed'])->toBe(0, json_encode(array_values(array_filter($summary['results'], fn ($r) => $r['status'] === 'fail')), JSON_PRETTY_PRINT));
    expect($summary['skipped'])->toBe(0);
    expect($summary['passed'])->toBe(88);
});

it('runs every http/error-envelope row, the normaliser included', function (): void {
    $summary = Conformance::runTable('http/error-envelope', fn (array $c) => Rows::errorEnvelope($c));
    fwrite(STDOUT, "\n".Conformance::formatSummary($summary)."\n");

    expect($summary['failed'])->toBe(0, json_encode(array_values(array_filter($summary['results'], fn ($r) => $r['status'] === 'fail')), JSON_PRETTY_PRINT));
    expect($summary['skipped'])->toBe(0);
    expect($summary['passed'])->toBe(82);
});
