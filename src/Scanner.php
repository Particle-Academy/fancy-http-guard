<?php

declare(strict_types=1);

namespace ParticleAcademy\HttpGuard;

/**
 * The pre-parse scan (fancy-conformance suites/http/SPEC.md section 3.3).
 *
 * One pass over the BYTES with an explicit stack: no tree, no recursion, no
 * json_decode. It answers what a parser answers only after paying for the whole
 * document: how deep, how many members, and is every number a finite double.
 * It stops at the first bomb, so 100,000 open brackets cost 33 steps.
 *
 * PHP's own json_decode turns `1e400` into INF without complaint, and Python's
 * accepts `NaN`; both are refused here, with the field path.
 *
 * Structural characters are ASCII, so walking bytes is safe: no UTF-8
 * continuation byte can be mistaken for one. The caller checks the body is
 * valid UTF-8 first.
 */
final class Scanner
{
    private const WS = " \t\n\r";

    private const DIGITS = '0123456789';

    /** @var list<array<string, mixed>> */
    private array $items = [];

    private int $i = 0;

    private int $n;

    /** @param  array<string, mixed>  $limits */
    private function __construct(private readonly string $text, private readonly array $limits)
    {
        $this->n = strlen($text);
    }

    /**
     * @param  array<string, mixed>  $limits
     * @return array{fault: bool, items: list<array<string, mixed>>}
     */
    public static function scan(string $text, array $limits): array
    {
        $s = new self($text, $limits);
        try {
            $s->run();
        } catch (ScanFault) {
            return ['fault' => true, 'items' => []];
        } catch (ScanStop) {
            // a bomb, or maxErrors reached: the items so far are the verdict
        }

        return ['fault' => false, 'items' => $s->items];
    }

    private function run(): void
    {
        $stack = [];
        $segs = [];
        $mode = 'value';
        $t = $this->text;
        while (true) {
            if ($mode === 'value') {
                $this->ws();
                if ($this->i >= $this->n) {
                    throw new ScanFault;
                }
                $c = $t[$this->i];
                if ($c === '[' || $c === '{') {
                    if (count($stack) + 1 > $this->limits['maxDepth']) {
                        $max = $this->limits['maxDepth'];
                        $this->items[] = self::withField([
                            'error' => 'too_deep',
                            'message' => 'This is nested more than '.Sentences::formatCount($max).' levels deep.',
                            'limit' => ['name' => 'maxDepth', 'max' => $max],
                        ], $segs);
                        throw new ScanStop;
                    }
                    $this->i++;
                    $frame = ['kind' => $c === '[' ? 'a' : 'o', 'segs' => $segs, 'count' => 0];
                    $this->ws();
                    if ($this->i < $this->n && $t[$this->i] === ($frame['kind'] === 'a' ? ']' : '}')) {
                        $this->i++;
                        $mode = 'after';

                        continue;
                    }
                    if ($frame['kind'] === 'a') {
                        $this->member($frame);
                        $segs = [...$frame['segs'], '0'];
                    } else {
                        $key = $this->key();
                        $this->member($frame);
                        $segs = [...$frame['segs'], $key];
                    }
                    $stack[] = $frame;

                    continue;
                }
                if ($c === '"') {
                    $this->string();
                    $mode = 'after';

                    continue;
                }
                if ($this->at('NaN') || $this->at('Infinity') || $this->at('-Infinity')) {
                    $this->i += $this->at('NaN') ? 3 : ($this->at('Infinity') ? 8 : 9);
                    $this->notFinite($segs);
                    $mode = 'after';

                    continue;
                }
                if ($c === '-' || ($c >= '0' && $c <= '9')) {
                    $start = $this->i;
                    if ($t[$this->i] === '-') {
                        $this->i++;
                    }
                    if ($this->i < $this->n && $t[$this->i] === '0') {
                        $this->i++;
                    } else {
                        $this->digits();
                    }
                    if ($this->i < $this->n && $t[$this->i] === '.') {
                        $this->i++;
                        $this->digits();
                    }
                    if ($this->i < $this->n && ($t[$this->i] === 'e' || $t[$this->i] === 'E')) {
                        $this->i++;
                        if ($this->i < $this->n && ($t[$this->i] === '+' || $t[$this->i] === '-')) {
                            $this->i++;
                        }
                        $this->digits();
                    }
                    if (! is_finite((float) substr($t, $start, $this->i - $start))) {
                        $this->notFinite($segs);
                    }
                    $mode = 'after';

                    continue;
                }
                foreach (['true', 'false', 'null'] as $literal) {
                    if ($this->at($literal)) {
                        $this->i += strlen($literal);
                        $mode = 'after';

                        continue 2;
                    }
                }
                throw new ScanFault;
            }

            // after a value
            $this->ws();
            if ($stack === []) {
                if ($this->i < $this->n) {
                    throw new ScanFault;
                }

                return;
            }
            if ($this->i >= $this->n) {
                throw new ScanFault;
            }
            $top = count($stack) - 1;
            $c = $t[$this->i];
            if ($c === ',') {
                $this->i++;
                $this->ws();
                if ($stack[$top]['kind'] === 'a') {
                    $this->member($stack[$top]);
                    $segs = [...$stack[$top]['segs'], (string) ($stack[$top]['count'] - 1)];
                } else {
                    $key = $this->key();
                    $this->member($stack[$top]);
                    $segs = [...$stack[$top]['segs'], $key];
                }
                $mode = 'value';

                continue;
            }
            if ($c === ($stack[$top]['kind'] === 'a' ? ']' : '}')) {
                $this->i++;
                array_pop($stack);

                continue;
            }
            throw new ScanFault;
        }
    }

    private function at(string $token): bool
    {
        return substr_compare($this->text, $token, $this->i, strlen($token)) === 0;
    }

    private function ws(): void
    {
        $this->i += strspn($this->text, self::WS, $this->i);
    }

    private function digits(): void
    {
        $len = strspn($this->text, self::DIGITS, $this->i);
        if ($len === 0) {
            throw new ScanFault;
        }
        $this->i += $len;
    }

    /** @param  array{kind: string, segs: list<string>, count: int}  $frame */
    private function member(array &$frame): void
    {
        $frame['count']++;
        $max = $this->limits['maxListLength'];
        if ($frame['count'] > $max) {
            $this->items[] = self::withField([
                'error' => 'list_too_long',
                'message' => 'This has more than '.Sentences::formatCount($max).' items.',
                'limit' => ['name' => 'maxListLength', 'max' => $max],
            ], $frame['segs']);
            throw new ScanStop;
        }
    }

    /** @param  list<string>  $segs */
    private function notFinite(array $segs): void
    {
        if (! $this->limits['finiteNumbers']) {
            return;
        }
        $this->items[] = self::withField(['error' => 'not_finite', 'message' => Sentences::NOT_FINITE], $segs);
        if (count($this->items) >= $this->limits['maxErrors']) {
            throw new ScanStop;
        }
    }

    private function key(): string
    {
        if ($this->i >= $this->n || $this->text[$this->i] !== '"') {
            throw new ScanFault;
        }
        $key = $this->string();
        $this->ws();
        if ($this->i >= $this->n || $this->text[$this->i] !== ':') {
            throw new ScanFault;
        }
        $this->i++;

        return $key;
    }

    /** A string at the cursor, decoded (keys become path segments). */
    private function string(): string
    {
        $t = $this->text;
        $this->i++;
        $out = '';
        while ($this->i < $this->n) {
            // Jump to the next quote, backslash or control byte.
            $run = strcspn($t, "\"\\\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f", $this->i);
            $out .= substr($t, $this->i, $run);
            $this->i += $run;
            if ($this->i >= $this->n) {
                break;
            }
            $c = $t[$this->i];
            if ($c === '"') {
                $this->i++;

                return $out;
            }
            if (ord($c) < 0x20) {
                throw new ScanFault;
            }
            // a backslash
            if ($this->i + 1 >= $this->n) {
                throw new ScanFault;
            }
            $e = $t[$this->i + 1];
            $this->i += 2;
            $simple = ['"' => '"', '\\' => '\\', '/' => '/', 'b' => "\x08", 'f' => "\x0c", 'n' => "\n", 'r' => "\r", 't' => "\t"];
            if (array_key_exists($e, $simple)) {
                $out .= $simple[$e];

                continue;
            }
            if ($e !== 'u') {
                throw new ScanFault;
            }
            $cu = $this->hex4();
            if ($cu >= 0xD800 && $cu <= 0xDBFF && substr($t, $this->i, 2) === '\\u') {
                $save = $this->i;
                $this->i += 2;
                $lo = $this->hex4();
                if ($lo >= 0xDC00 && $lo <= 0xDFFF) {
                    $out .= mb_chr(0x10000 + (($cu - 0xD800) << 10) + ($lo - 0xDC00), 'UTF-8');

                    continue;
                }
                $this->i = $save;
            }
            $out .= ($cu >= 0xD800 && $cu <= 0xDFFF) ? "\u{FFFD}" : mb_chr($cu, 'UTF-8');
        }
        throw new ScanFault;
    }

    private function hex4(): int
    {
        $hex = substr($this->text, $this->i, 4);
        if (strlen($hex) !== 4 || strspn($hex, '0123456789abcdefABCDEF') !== 4) {
            throw new ScanFault;
        }
        $this->i += 4;

        return (int) hexdec($hex);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<string>  $segs
     * @return array<string, mixed>
     */
    private static function withField(array $item, array $segs): array
    {
        if ($segs !== []) {
            $item['field'] = implode('.', $segs);
        }

        return $item;
    }
}

/** @internal a grammar fault: the whole verdict is a 400 */
final class ScanFault extends \Exception {}

/** @internal a bomb or maxErrors: stop, the items so far are the verdict */
final class ScanStop extends \Exception {}
