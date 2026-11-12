<?php

declare(strict_types=1);

use ParticleAcademy\HttpGuard\Tests\Support\Rows;

$repo = dirname(__DIR__, 2);
$lf = fn (string $path): string => str_replace("\r\n", "\n", (string) file_get_contents($path));

it('the conformance checkout on disk is the pinned version', function (): void {
    expect(trim((string) file_get_contents(Rows::root().'/VERSION')))->toBe(Rows::PINNED_SUITE_VERSION);
});

it('CI checks out the tag this suite pins, never main', function () use ($repo): void {
    $ci = (string) file_get_contents($repo.'/.github/workflows/ci.yml');
    preg_match_all('~repository: Particle-Academy/fancy-conformance\s+ref: (\S+)~', $ci, $m);
    expect($m[1])->toBe(['v'.Rows::PINNED_SUITE_VERSION]);
});

it('ships a byte copy of the contract schema', function () use ($repo, $lf): void {
    // LF-normalised: this estate checks out with core.autocrlf=true.
    $ours = hash('sha256', $lf($repo.'/resources/envelope.schema.json'));
    $theirs = hash('sha256', $lf(Rows::root().'/suites/http/error-envelope/envelope.schema.json'));
    expect($ours)->toBe($theirs);
});

it('has no third-party runtime dependency', function () use ($repo): void {
    $require = json_decode((string) file_get_contents($repo.'/composer.json'), true)['require'];
    foreach (array_keys($require) as $name) {
        expect($name === 'php' || str_starts_with($name, 'ext-') || str_starts_with($name, 'illuminate/'))
            ->toBeTrue("{$name} is a runtime dependency; only Laravel's own components are");
    }
});
