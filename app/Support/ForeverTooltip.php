<?php

namespace App\Support;

/**
 * Fills a WoW Forever tooltip ("Stuns the target for $d.") from the game's own tables, for
 * `forever:abilities`. It works out only what the tables state outright, and returns null for a
 * tooltip with anything else in it, so a page never shows a raw token or a guessed number:
 *
 * - `$s1` `$m1` `$w1`: the effect's value, unless the effect has a Variance. Forever's damage spells
 *   carry one (Frostbolt rank 1: 19 with 0.407, where Classic's tooltip read "18 to 20"), and how it
 *   becomes the tooltip's range has not been checked against the game, so those stay unresolved.
 * - `$o1` the whole periodic amount, `$t1` its tick, `$a1` radius, `$x1` chain targets, `$d` the
 *   duration, `$i` how many targets, `$b1` points per combo point, `$h` proc chance, `$u` stacks, `$n` charges, `$r` range, `$/10;s1` a division,
 *   `${...}` arithmetic over those, `$lsec:secs;` the plural, and `$12345s1` another spell's.
 * - Never: `$<variable>`, `$?condition[...]`, `$@spelldesc`, or any letter not listed.
 */
class ForeverTooltip
{
    /**
     * @param  array{effects: array<int, array<string, string>>, duration_ms: int, radius: callable, range: float,
     *     proc_chance: int, stacks: int, charges: int, max_targets?: int, other?: callable}  $ctx  `other(int $spellId)` returns another spell's ctx
     */
    public function resolve(string $text, array $ctx): ?string
    {
        if ($text === '' || preg_match('/\$(\?|@|<)/', $text)) {
            return null;
        }

        $failed = false;
        $lastNumber = null;

        // ${ expression } first, so its inner tokens are resolved as numbers.
        $text = preg_replace_callback('/\$\{([^{}]*)\}(\.\d)?/', function ($m) use ($ctx, &$failed) {
            $inner = preg_replace_callback('/\$(\d*)([a-zA-Z])(\d?)/', function ($t) use ($ctx, &$failed) {
                $v = $this->number($t[1], $t[2], $t[3], $ctx);
                if ($v === null) {
                    $failed = true;

                    return '0';
                }

                return (string) $v;
            }, $m[1]);
            $value = $this->evaluate($inner);
            if ($value === null) {
                $failed = true;

                return '';
            }
            $decimals = isset($m[2]) ? (int) substr($m[2], 1) : 0;

            return $this->format($value, $decimals);
        }, $text);

        $text = preg_replace_callback('/\$\/(\d+);(\d*)([a-zA-Z])(\d)/', function ($m) use ($ctx, &$failed) {
            $v = $this->number($m[2], $m[3], $m[4], $ctx);
            if ($v === null || (int) $m[1] === 0) {
                $failed = true;

                return '';
            }

            return $this->format($v / (int) $m[1]);
        }, $text);

        // Plurals and plain tokens, left to right, so "$d $lsecond:seconds;" knows its number.
        $text = preg_replace_callback('/\$[lL]([^:;]*):([^;]*);|\$(\d*)([a-zA-Z])(\d?)/', function ($m) use ($ctx, &$failed, &$lastNumber) {
            if (($m[1] ?? '') !== '' || ($m[2] ?? '') !== '') {
                return $lastNumber !== null && (float) $lastNumber === 1.0 ? $m[1] : $m[2];
            }
            if (strtolower($m[4]) === 'd' && $m[5] === '') {
                $ms = $m[3] === '' ? $ctx['duration_ms'] : (($ctx['other'] ?? fn () => null)((int) $m[3])['duration_ms'] ?? 0);
                if ($ms <= 0) {
                    $failed = true;

                    return '';
                }
                $lastNumber = null;

                return $this->duration($ms);
            }
            $v = $this->number($m[3], $m[4], $m[5], $ctx);
            if ($v === null) {
                $failed = true;

                return '';
            }
            $lastNumber = $v;

            return $this->format($v);
        }, $text);

        if ($failed || str_contains($text, '$')) {
            return null;
        }

        return trim(preg_replace('/[ \t]{2,}/', ' ', str_replace(["\r\n", "\r"], "\n", $text)));
    }

    /** A token's number, or null when the tables don't settle it. */
    private function number(string $spell, string $letter, string $index, array $ctx): ?float
    {
        if ($spell !== '') {
            $other = isset($ctx['other']) ? ($ctx['other'])((int) $spell) : null;
            if ($other === null) {
                return null;
            }
            $ctx = $other;
        }
        // A bare $a is the first effect's radius.
        if ($index === '' && ($letter === 'a' || $letter === 'A')) {
            $index = '1';
        }
        $effect = $index !== '' ? ($ctx['effects'][(int) $index - 1] ?? null) : null;
        $base = $effect ? abs((float) $effect['EffectBasePointsF']) : null;
        $variance = $effect ? (float) $effect['Variance'] : 0.0;

        return match ($letter) {
            's', 'S', 'm', 'w', 'W' => $effect && $variance == 0.0 ? $base : null,
            'M' => $effect && $variance == 0.0 ? $base : null,
            'o', 'O' => $effect && $variance == 0.0 && (int) $effect['EffectAuraPeriod'] > 0 && $ctx['duration_ms'] > 0
                ? $base * intdiv($ctx['duration_ms'], (int) $effect['EffectAuraPeriod']) : null,
            't', 'T' => $effect && (int) $effect['EffectAuraPeriod'] > 0 ? (int) $effect['EffectAuraPeriod'] / 1000 : null,
            // An effect keeps its radius in one of two slots (Psychic Scream's is in the second).
            'a', 'A' => $effect ? ($ctx['radius'])((int) $effect['EffectRadiusIndex_0'] ?: (int) $effect['EffectRadiusIndex_1']) : null,
            'x', 'X' => $effect && (int) $effect['EffectChainTargets'] > 0 ? (float) $effect['EffectChainTargets'] : null,
            'b', 'B' => $effect && (float) $effect['EffectPointsPerResource'] != 0.0 ? abs((float) $effect['EffectPointsPerResource']) : null,
            'i', 'I' => $index === '' && ($ctx['max_targets'] ?? 0) > 0 ? (float) $ctx['max_targets'] : null,
            'h', 'H' => $index === '' && $ctx['proc_chance'] > 0 ? (float) $ctx['proc_chance'] : null,
            'u', 'U' => $index === '' && $ctx['stacks'] > 0 ? (float) $ctx['stacks'] : null,
            'n', 'N' => $index === '' && $ctx['charges'] > 0 ? (float) $ctx['charges'] : null,
            'r', 'R' => $index === '' && $ctx['range'] > 0 ? $ctx['range'] : null,
            'd', 'D' => $index === '' && $ctx['duration_ms'] > 0 ? $ctx['duration_ms'] / 1000 : null,
            default => null,
        };
    }

    /** As the game writes it: "8 sec", "1.5 sec", "2 min", "1 hour". */
    public function duration(int $ms): string
    {
        $s = $ms / 1000;
        if ($s >= 3600 && fmod($s, 3600) == 0) {
            $h = (int) ($s / 3600);

            return $h.($h === 1 ? ' hour' : ' hours');
        }
        if ($s >= 60 && fmod($s, 60) == 0) {
            return ((int) ($s / 60)).' min';
        }

        return $this->format($s).' sec';
    }

    private function format(float $v, int $decimals = 0): string
    {
        if ($decimals > 0) {
            return number_format($v, $decimals, '.', '');
        }

        return abs($v - round($v)) < 0.001 ? (string) (int) round($v) : rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    /** + - * / and brackets over numbers; anything else is null. */
    public function evaluate(string $expr): ?float
    {
        $expr = preg_replace('/\s+/', '', $expr);
        if ($expr === '' || ! preg_match('/^[\d.+\-*\/()]+$/', $expr)) {
            return null;
        }
        $pos = 0;
        try {
            $v = $this->sum($expr, $pos);
        } catch (\Throwable) {
            return null;
        }

        return $pos === strlen($expr) ? $v : null;
    }

    private function sum(string $e, int &$p): float
    {
        $v = $this->product($e, $p);
        while ($p < strlen($e) && ($e[$p] === '+' || $e[$p] === '-')) {
            $op = $e[$p++];
            $r = $this->product($e, $p);
            $v = $op === '+' ? $v + $r : $v - $r;
        }

        return $v;
    }

    private function product(string $e, int &$p): float
    {
        $v = $this->atom($e, $p);
        while ($p < strlen($e) && ($e[$p] === '*' || $e[$p] === '/')) {
            $op = $e[$p++];
            $r = $this->atom($e, $p);
            if ($op === '/' && $r == 0.0) {
                throw new \DivisionByZeroError;
            }
            $v = $op === '*' ? $v * $r : $v / $r;
        }

        return $v;
    }

    private function atom(string $e, int &$p): float
    {
        if ($p < strlen($e) && $e[$p] === '-') {
            $p++;

            return -$this->atom($e, $p);
        }
        if ($p < strlen($e) && $e[$p] === '(') {
            $p++;
            $v = $this->sum($e, $p);
            if (($e[$p] ?? '') !== ')') {
                throw new \RuntimeException('bracket');
            }
            $p++;

            return $v;
        }
        if (! preg_match('/\G\d+(\.\d+)?/', $e, $m, 0, $p)) {
            throw new \RuntimeException('number');
        }
        $p += strlen($m[0]);

        return (float) $m[0];
    }
}
