<?php

namespace App\Http\Services;

use App\Models\Specialization;
use App\Models\TalentNode;
use App\Models\TalentNodeEntry;
use Illuminate\Support\Collection;

/**
 * Decodes (and, internally, encodes for test fixtures) Blizzard's in-game talent loadout
 * "Export" string — the same format Wowhead/Raidbots/the in-game copy button produce. This is
 * not a reverse-engineering guess: the bit-packing algorithm below is a literal port of
 * Blizzard's own shipped client Lua (`Blizzard_ClassTalentImportExport.lua` +
 * `Blizzard_SharedXMLBase/ExportUtil.lua`, pulled from the Gethe/wow-ui-source mirror,
 * 2026-07-31) and was verified against a real string before this class was written: it decodes
 * to spec ID 256 (Discipline Priest), confirming the port is correct.
 *
 * Format (from the verified source):
 * - Base64 alphabet is the standard one (A-Z, a-z, 0-9, +, /) but bits are packed LSB-first
 *   across 6-bit groups, spanning char boundaries arbitrarily — NOT compatible with
 *   base64_decode(), which is byte-aligned and MSB-first. Must be hand-rolled (see BitReader/
 *   BitWriter below).
 * - Header: 8-bit serialization version, 16-bit spec ID, 128-bit tree hash (16 bytes). The tree
 *   hash validates against a native-code hash we cannot compute — Blizzard's own client
 *   explicitly sanctions third-party tools skipping it ("can be omitted and zero-filled, which
 *   will ignore the extra validation"), so it's read and discarded here, never checked.
 * - Content: one bit per talent node, in ascending order by Blizzard's internal node ID, across
 *   the WHOLE CLASS's trait tree (every spec's spec-tree nodes and every hero tree's nodes for
 *   that class — not just the spec being imported into). Per node: is-selected (1 bit) → if
 *   selected: is-purchased (1 bit) → if purchased: is-partially-ranked (1 bit) [+ 6 bits
 *   ranks-purchased] → is-choice-node (1 bit) [+ 2 bits choice-index].
 * - PvP talents are not part of this format at all — a Blizzard string can never set PvP picks.
 *
 * Two assumptions this class makes that could not be fully verified without real imported data
 * on hand while building it — flagged here rather than silently trusted, and mitigated by
 * TalentSelector's preview-before-apply flow (nothing is written to talent_builds until a human
 * confirms the decoded spell names look right):
 * 1. Choice-node index -> talent_node_entries mapping: resolved via entries sorted by `id` ASC
 *    (insertion order from ImportSpellData::importTreeNodes(), which writes in the Game Data
 *    API's own `choices[]` array order). Assumes that array order matches the live client's
 *    `entryIDs` order Blizzard encodes the choice index against — very likely (same underlying
 *    source data) but not proven.
 * 2. Hero-tree-selection nodes need no special-casing at the bit level (the choice flag is
 *    self-describing in the stream, not derived from our own stored node type) — confirmed by
 *    reading the source, not yet cross-checked against real decoded output.
 */
class BlizzardTalentStringCodec
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

    private const BITS_PER_CHAR = 6;

    private const HEADER_VERSION_BITS = 8;

    private const HEADER_SPEC_ID_BITS = 16;

    private const HEADER_TREE_HASH_BYTES = 16;

    private const RANKS_PURCHASED_BITS = 6;

    /** The one bit-layout version this class has been verified against — see class docblock. */
    private const SUPPORTED_VERSION = 2;

    /** @return array{version: int, specId: int} */
    public function decodeHeader(string $importString): array
    {
        $reader = new BlizzardBitReader($this->base64ToValues($importString));

        $version = $reader->extract(self::HEADER_VERSION_BITS);
        $specId = $reader->extract(self::HEADER_SPEC_ID_BITS);

        if ($version === null || $specId === null) {
            throw new \InvalidArgumentException('Talent string is too short to contain a valid header.');
        }

        return ['version' => $version, 'specId' => $specId];
    }

    /**
     * @return array{selections: Collection<int, array{node: TalentNode, entry: \App\Models\TalentNodeEntry}>, warnings: array<int, string>}
     */
    public function decode(string $importString, Specialization $targetSpec, int $patchId): array
    {
        $reader = new BlizzardBitReader($this->base64ToValues($importString));

        $version = $reader->extract(self::HEADER_VERSION_BITS);
        $specId = $reader->extract(self::HEADER_SPEC_ID_BITS);

        if ($version === null || $specId === null) {
            throw new \InvalidArgumentException('Talent string is too short to contain a valid header.');
        }

        if ($version !== self::SUPPORTED_VERSION) {
            throw new \InvalidArgumentException("Unsupported talent string version ({$version}) — only version ".self::SUPPORTED_VERSION.' has been verified against Blizzard\'s current format.');
        }

        if ($specId !== $targetSpec->external_spec_id) {
            throw new \InvalidArgumentException("This string is for a different specialization (spec ID {$specId}), not {$targetSpec->name}.");
        }

        // Tree hash — 16 bytes, read and discarded (see class docblock).
        for ($i = 0; $i < self::HEADER_TREE_HASH_BYTES; $i++) {
            if ($reader->extract(8) === null) {
                throw new \InvalidArgumentException('Talent string is too short to contain a valid tree hash.');
            }
        }

        $nodes = TalentNode::whereHas(
            'talentTree',
            fn ($q) => $q->where('class_id', $targetSpec->class_id)->where('patch_id', $patchId)
        )
            ->orderBy('external_node_id')
            ->with(['entries.spell', 'talentTree'])
            ->get();

        $selections = collect();
        $warnings = [];

        foreach ($nodes as $node) {
            $isSelected = $reader->extract(1);

            if ($isSelected === null) {
                $warnings[] = "Talent string ended early — nodes after '{$node->external_node_id}' were not read.";
                break;
            }

            if ($isSelected !== 1) {
                continue;
            }

            $isPurchased = $reader->extract(1);
            $ranksPurchased = null;
            $choiceIndex = null;

            if ($isPurchased === 1) {
                $isPartiallyRanked = $reader->extract(1);

                if ($isPartiallyRanked === 1) {
                    $ranksPurchased = $reader->extract(self::RANKS_PURCHASED_BITS);
                }

                $isChoiceNode = $reader->extract(1);

                if ($isChoiceNode === 1) {
                    $choiceIndex = $reader->extract(2);
                }
            }

            $entry = $this->resolveEntry($node, $ranksPurchased, $choiceIndex);

            if ($entry === null) {
                $warnings[] = "Could not resolve a talent entry for node #{$node->external_node_id} — skipped.";

                continue;
            }

            $selections->push(['node' => $node, 'entry' => $entry]);
        }

        return ['selections' => $selections, 'warnings' => $warnings];
    }

    /**
     * Choice node: entries sorted by id (insertion/choices[] order — assumption #1, see class
     * docblock), pick the given zero-based index. Ranked node: the entry matching the exact
     * ranks-purchased count, falling back to the highest-rank entry when not partially ranked
     * (max rank assumed, per the format spec). Single-entry node: its one entry.
     */
    private function resolveEntry(TalentNode $node, ?int $ranksPurchased, ?int $choiceIndex): ?TalentNodeEntry
    {
        $entries = $node->entries;

        if ($entries->isEmpty()) {
            return null;
        }

        if ($choiceIndex !== null) {
            return $entries->sortBy('id')->values()->get($choiceIndex);
        }

        if ($ranksPurchased !== null) {
            return $entries->firstWhere('rank', $ranksPurchased) ?? $entries->sortByDesc('rank')->first();
        }

        return $entries->sortByDesc('rank')->first();
    }

    /** @return array<int, int> */
    private function base64ToValues(string $importString): array
    {
        $reverse = array_flip(str_split(self::ALPHABET));
        $values = [];

        for ($i = 0; $i < strlen($importString); $i++) {
            $char = $importString[$i];

            if (!isset($reverse[$char])) {
                throw new \InvalidArgumentException("Talent string contains an invalid character: '{$char}'.");
            }

            $values[] = $reverse[$char];
        }

        return $values;
    }

    /**
     * Test-fixture support only — mirrors Blizzard's ConvertToBase64/AddValue exactly, so unit
     * tests can build known-good strings from a list of (bitWidth, value) pairs instead of
     * hand-writing base64. Not exposed to the UI in this pass (export wasn't requested — see
     * class docblock).
     *
     * @param  array<int, array{bitWidth: int, value: int}>  $entries
     */
    public function encode(array $entries): string
    {
        $alphabet = str_split(self::ALPHABET);
        $exportString = '';
        $currentValue = 0;
        $currentReservedBits = 0;

        foreach ($entries as $entry) {
            $remainingValue = $entry['value'];
            $remainingRequiredBits = $entry['bitWidth'];

            while ($remainingRequiredBits > 0) {
                $spaceInCurrentValue = self::BITS_PER_CHAR - $currentReservedBits;
                $maxStorableValue = 1 << $spaceInCurrentValue;
                $remainder = $remainingValue % $maxStorableValue;
                $remainingValue = $remainingValue >> $spaceInCurrentValue;
                $currentValue += $remainder << $currentReservedBits;

                if ($spaceInCurrentValue > $remainingRequiredBits) {
                    $currentReservedBits = ($currentReservedBits + $remainingRequiredBits) % self::BITS_PER_CHAR;
                    $remainingRequiredBits = 0;
                } else {
                    $exportString .= $alphabet[$currentValue];
                    $currentValue = 0;
                    $currentReservedBits = 0;
                    $remainingRequiredBits -= $spaceInCurrentValue;
                }
            }
        }

        if ($currentReservedBits > 0) {
            $exportString .= $alphabet[$currentValue];
        }

        return $exportString;
    }
}

/**
 * Bit-level reader mirroring Blizzard's ImportDataStreamMixin:ExtractValue exactly — LSB-first,
 * spans 6-bit char boundaries arbitrarily. Returns null once the stream is exhausted rather than
 * throwing, so callers can treat "ran out of bits" as a recoverable warning (a truncated/corrupt
 * paste) instead of a crash.
 */
class BlizzardBitReader
{
    private array $values;

    private int $index = 0;

    private int $extractedBits = 0;

    private ?int $remaining;

    public function __construct(array $values)
    {
        $this->values = $values;
        $this->remaining = $values[0] ?? null;
    }

    public function extract(int $bitWidth): ?int
    {
        if ($this->index >= count($this->values) || $this->remaining === null) {
            return null;
        }

        $value = 0;
        $bitsNeeded = $bitWidth;
        $extracted = 0;

        while ($bitsNeeded > 0) {
            $remainingBitsInChar = 6 - $this->extractedBits;
            $bitsToExtract = min($remainingBitsInChar, $bitsNeeded);
            $this->extractedBits += $bitsToExtract;
            $maxStorable = 1 << $bitsToExtract;
            $remainder = $this->remaining % $maxStorable;
            $this->remaining = $this->remaining >> $bitsToExtract;
            $value += $remainder << $extracted;
            $extracted += $bitsToExtract;
            $bitsNeeded -= $bitsToExtract;

            if ($bitsToExtract < $remainingBitsInChar) {
                break;
            }

            $this->index++;
            $this->extractedBits = 0;
            $this->remaining = $this->values[$this->index] ?? null;

            if ($bitsNeeded > 0 && $this->remaining === null) {
                return null;
            }
        }

        return $value;
    }
}
