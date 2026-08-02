<?php

namespace App\Http\Services;

use RuntimeException;

/**
 * Parses a WoW SavedVariables file into a plain PHP array. SavedVariables is a restricted,
 * predictable Lua subset — always exactly `VarName = { ... }` (optionally more than one such
 * assignment per file, though this project only ever writes one: MindCollectorExportDB), where
 * the table body is nested `["key"] = value,` / `[N] = value,` / bare-identifier-key pairs and
 * array-style sequential entries. No general Lua parser is needed (and none exists in this repo
 * or vendor/) — a small dedicated tokenizer + recursive-descent table parser is enough, per
 * spellbook-verifier.md's Part 2 investigation.
 *
 * Deliberately does not evaluate Lua expressions, function calls, or comments beyond `--` to
 * end-of-line (which WoW's serializer emits as array-index annotations, e.g. `}, -- [1]`, and
 * which carry no data — skipped, not parsed).
 */
class SavedVariablesLuaParser
{
    private string $src;

    private int $pos;

    private int $len;

    /**
     * @return array<string, mixed> the value of the first top-level `Name = { ... }` assignment
     *                               whose name matches $variableName
     */
    public function parseVariable(string $contents, string $variableName): array
    {
        $this->src = $contents;
        $this->len = strlen($contents);
        $this->pos = 0;

        while ($this->pos < $this->len) {
            $this->skipWhitespaceAndComments();

            if ($this->pos >= $this->len) {
                break;
            }

            $name = $this->readIdentifier();

            if ($name === null) {
                // Not an assignment start we recognise — advance one char and keep scanning
                // rather than failing the whole file over stray content.
                $this->pos++;

                continue;
            }

            $this->skipWhitespaceAndComments();
            $this->expect('=');
            $this->skipWhitespaceAndComments();

            $value = $this->parseValue();

            if ($name === $variableName) {
                if (!is_array($value)) {
                    throw new RuntimeException("SavedVariables '{$variableName}' is not a table.");
                }

                return $value;
            }

            $this->skipWhitespaceAndComments();
            if ($this->peek() === ';') {
                $this->pos++;
            }
        }

        throw new RuntimeException("SavedVariables assignment '{$variableName}' not found.");
    }

    private function parseValue(): mixed
    {
        $this->skipWhitespaceAndComments();
        $ch = $this->peek();

        if ($ch === '{') {
            return $this->parseTable();
        }

        if ($ch === '"' || $ch === "'") {
            return $this->parseString();
        }

        if ($this->matchesKeyword('true')) {
            $this->pos += 4;

            return true;
        }

        if ($this->matchesKeyword('false')) {
            $this->pos += 5;

            return false;
        }

        if ($this->matchesKeyword('nil')) {
            $this->pos += 3;

            return null;
        }

        return $this->parseNumber();
    }

    /**
     * @return array<int|string, mixed>
     */
    private function parseTable(): array
    {
        $this->expect('{');
        $result = [];
        $nextIndex = 1;

        while (true) {
            $this->skipWhitespaceAndComments();
            $ch = $this->peek();

            if ($ch === '}') {
                $this->pos++;
                break;
            }

            if ($ch === null) {
                throw new RuntimeException('Unterminated Lua table — reached end of file.');
            }

            [$key, $value] = $this->parseTableEntry($nextIndex);

            if (is_int($key)) {
                $nextIndex = max($nextIndex, $key + 1);
            }

            $result[$key] = $value;

            $this->skipWhitespaceAndComments();
            if ($this->peek() === ',' || $this->peek() === ';') {
                $this->pos++;
            }
        }

        return $result;
    }

    /**
     * @return array{0: int|string, 1: mixed}
     */
    private function parseTableEntry(int $defaultIndex): array
    {
        $this->skipWhitespaceAndComments();

        if ($this->peek() === '[') {
            $this->pos++;
            $this->skipWhitespaceAndComments();

            $key = $this->peek() === '"' || $this->peek() === "'"
                ? $this->parseString()
                : (int) $this->parseNumber();

            $this->skipWhitespaceAndComments();
            $this->expect(']');
            $this->skipWhitespaceAndComments();
            $this->expect('=');
            $this->skipWhitespaceAndComments();

            return [$key, $this->parseValue()];
        }

        // Bare identifier key, e.g. `class = "PRIEST",` — used by hand-written fixtures; WoW's
        // own serializer always emits the `["key"] =` form above, but both are valid Lua.
        $identStart = $this->pos;
        $ident = $this->readIdentifier();

        if ($ident !== null) {
            $this->skipWhitespaceAndComments();
            if ($this->peek() === '=') {
                $this->pos++;
                $this->skipWhitespaceAndComments();

                return [$ident, $this->parseValue()];
            }

            // It wasn't actually `ident =` (e.g. a bare `nil`/`true` value) — rewind and parse
            // as a positional array value instead.
            $this->pos = $identStart;
        }

        return [$defaultIndex, $this->parseValue()];
    }

    private function parseString(): string
    {
        $quote = $this->peek();
        $this->pos++;
        $out = '';

        while (true) {
            $ch = $this->peek();

            if ($ch === null) {
                throw new RuntimeException('Unterminated Lua string.');
            }

            if ($ch === $quote) {
                $this->pos++;
                break;
            }

            if ($ch === '\\') {
                $this->pos++;
                $escaped = $this->peek();
                $out .= match ($escaped) {
                    'n' => "\n",
                    't' => "\t",
                    'r' => "\r",
                    '"' => '"',
                    "'" => "'",
                    '\\' => '\\',
                    default => $escaped ?? '',
                };
                $this->pos++;

                continue;
            }

            $out .= $ch;
            $this->pos++;
        }

        return $out;
    }

    private function parseNumber(): float|int
    {
        $start = $this->pos;

        if ($this->peek() === '-') {
            $this->pos++;
        }

        while ($this->pos < $this->len && (ctype_digit($this->src[$this->pos]) || $this->src[$this->pos] === '.')) {
            $this->pos++;
        }

        $raw = substr($this->src, $start, $this->pos - $start);

        if ($raw === '' || $raw === '-') {
            throw new RuntimeException("Expected a number at position {$start}, found: ".substr($this->src, $start, 20));
        }

        return str_contains($raw, '.') ? (float) $raw : (int) $raw;
    }

    private function readIdentifier(): ?string
    {
        $start = $this->pos;

        while ($this->pos < $this->len && preg_match('/[A-Za-z0-9_]/', $this->src[$this->pos])) {
            $this->pos++;
        }

        if ($this->pos === $start) {
            return null;
        }

        $ident = substr($this->src, $start, $this->pos - $start);

        // Identifier must start with a letter or underscore, not a digit (avoids misreading a
        // bare number as an identifier).
        if (!preg_match('/^[A-Za-z_]/', $ident)) {
            $this->pos = $start;

            return null;
        }

        return $ident;
    }

    private function matchesKeyword(string $keyword): bool
    {
        return substr($this->src, $this->pos, strlen($keyword)) === $keyword
            && !preg_match('/[A-Za-z0-9_]/', $this->src[$this->pos + strlen($keyword)] ?? '');
    }

    private function skipWhitespaceAndComments(): void
    {
        while ($this->pos < $this->len) {
            $ch = $this->src[$this->pos];

            if (ctype_space($ch)) {
                $this->pos++;

                continue;
            }

            if ($ch === '-' && ($this->src[$this->pos + 1] ?? '') === '-') {
                // `--[[ ... ]]` block comment or `-- ...` line comment.
                if (($this->src[$this->pos + 2] ?? '') === '[' && ($this->src[$this->pos + 3] ?? '') === '[') {
                    $end = strpos($this->src, ']]', $this->pos + 4);
                    $this->pos = $end === false ? $this->len : $end + 2;
                } else {
                    $end = strpos($this->src, "\n", $this->pos);
                    $this->pos = $end === false ? $this->len : $end + 1;
                }

                continue;
            }

            break;
        }
    }

    private function peek(): ?string
    {
        return $this->pos < $this->len ? $this->src[$this->pos] : null;
    }

    private function expect(string $char): void
    {
        if ($this->peek() !== $char) {
            throw new RuntimeException("Expected '{$char}' at position {$this->pos}, found: ".substr($this->src, $this->pos, 20));
        }

        $this->pos++;
    }
}
