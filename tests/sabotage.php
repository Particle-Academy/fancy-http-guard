<?php

declare(strict_types=1);

/*
 * Mutation sweep: weaken one guard at a time and require the suite to notice.
 *
 *     php tests/sabotage.php
 *
 * The suite and the implementation were written in one sitting, which is the
 * easiest way to write tests that assert only what the code already does. Each
 * mutation is a mistake a port would plausibly make; one that SURVIVES is a gap
 * in the suite. Each file is restored in a `finally`. The suite's output goes to
 * a file and the verdict is read from the exit code, never through a pipe.
 */

$root = dirname(__DIR__);
$src = $root.'/src/';

// [name, file, find, replace]; `find` must occur exactly once.
$mutations = [
    ['trusts a declared length', 'Guard.php', 'if ((int) $length > $cap) {', 'if (false) {'],
    ['no bounded read', 'Guard.php', 'if (strlen($data) > $cap) {', 'if (false) {'],
    ['misses the NaN/Infinity tokens', 'Scanner.php', "if (\$this->at('NaN') || \$this->at('Infinity') || \$this->at('-Infinity')) {", 'if (false) {'],
    ['misses overflow (1e400)', 'Scanner.php', 'if (! is_finite((float) substr($t, $start, $this->i - $start))) {', 'if (false) {'],
    ['depth off by one', 'Scanner.php', "if (count(\$stack) + 1 > \$this->limits['maxDepth']) {", "if (count(\$stack) + 1 >= \$this->limits['maxDepth']) {"],
    ['counts arrays only', 'Scanner.php', "if (\$frame['count'] > \$max) {", "if (\$frame['kind'] === 'a' && \$frame['count'] > \$max) {"],
    ['trusts any inbound id', 'Http/Middleware/GuardRequests.php', "        if (\$trust) {\n", "        if (true) {\n"],
    ['echoes a deliberate 5xx message', 'Renderer.php',
        'return Envelope::make($status, $code, Sentences::serverSentence($status, $retry, $rid), $rid, $rh, $retry);',
        'return Envelope::make($status, $code, $message ?: Sentences::serverSentence($status, $retry, $rid), $rid, $rh, $retry);'],
    ['echoes a crash', 'Renderer.php',
        "        return self::crash(\$rid, \$rh);\n    }\n\n    public static function crash(",
        "        return Envelope::make(500, 'server_error', \$e->getMessage(), \$rid, \$rh);\n    }\n\n    public static function crash("],
    ['lets the app forge the request id', 'Http/Middleware/GuardRequests.php', '$response->headers->set($rh, $rid);', '$response->headers->set($rh, $rid, false);'],
    ['scans only a declared JSON type', 'Guard.php', '$json = $media === null || ', '$json = '],
    ['keeps control characters', 'Sentences.php', 'if ($cp === 0x20 || $cp <= 0x1F || ($cp >= 0x7F && $cp <= 0x9F)) {', 'if ($cp === 0x20) {'],
    ['no part-size floor', 'Settings.php', "'maxPartBytes' => max(65536, 64 * \$cells),", "'maxPartBytes' => 64 * \$cells,"],
    ['shows framework-made messages', 'Renderer.php', '$own = $previous === null || $previous instanceof AuthorizationException;', '$own = true;'],
    ['runs after TrimStrings (appended)', 'FancyHttpGuard.php', '$middleware->prepend(GuardRequests::class);', '$middleware->append(GuardRequests::class);'],
    ['shows 5xx field text from an upstream', 'Client/ErrorNormaliser.php', '$serverSide ? [] : $fields', '$fields'],
    ['answers an Inertia 422 with JSON', 'Http/Middleware/GuardRequests.php', "if (\$refusal->status === 422 && \$request->headers->has('X-Inertia')) {", 'if (false) {'],
    ['half-to-even byte rounding', 'Sentences.php', '$tenths = intdiv($n * 10 + intdiv($unit, 2), $unit);', '$tenths = (int) round($n * 10 / $unit, 0, PHP_ROUND_HALF_EVEN);'],
    ["echoes holy-sheet's text", 'Renderer.php', 'Sentences::workbook($limit), $rid, $rh, null', '$e->getMessage(), $rid, $rh, null'],
];

$suite = static function () use ($root): int {
    $log = tempnam(sys_get_temp_dir(), 'sabotage');
    $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/vendor/bin/pest').' --stop-on-failure > '.escapeshellarg($log).' 2>&1';
    $cwd = getcwd();
    chdir($root);
    exec($cmd, $out, $code);
    chdir($cwd);
    @unlink($log);

    return $code;
};

if ($suite() !== 0) {
    fwrite(STDERR, "The unmutated suite fails; fix that before reading any mutation result.\n");
    exit(1);
}

$survived = [];
foreach ($mutations as [$name, $file, $find, $replace]) {
    $path = $src.$file;
    $original = (string) file_get_contents($path);
    $normal = str_replace("\r\n", "\n", $original);
    if (substr_count($normal, $find) !== 1) {
        echo "STALE    {$name}: the text occurs ".substr_count($normal, $find)." times in {$file}\n";
        $survived[] = $name;

        continue;
    }
    try {
        file_put_contents($path, str_replace($find, $replace, $normal));
        $code = $suite();
    } finally {
        file_put_contents($path, $original);
    }
    echo ($code !== 0 ? 'caught  ' : 'SURVIVED')." {$name}\n";
    if ($code === 0) {
        $survived[] = $name;
    }
}

echo "\n".(count($mutations) - count($survived)).' of '.count($mutations)." mutations caught\n";
exit($survived === [] ? 0 : 1);
