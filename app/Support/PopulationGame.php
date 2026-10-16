<?php

namespace App\Support;

/**
 * One measured game in the population's shape (2026-10-10): players under a hashed id, no names.
 * Written by wow:population from the PC's archive and by wow:pool-export from the rounds uploaded to
 * the website, so both sources are the same files and one build reads them together. The hash is
 * salted the same way in both, so a player met in the archive and in an upload is one player.
 */
class PopulationGame
{
    /**
     * One game as the aggregate needs it: no names, players under a stable hash. `$a` is a round's
     * stored analysis (RoundAnalysisService::analyse), `$meta` its metadata.
     */
    public static function compact(string $id, array $a, array $meta): array
    {
        $hash = fn (?string $guid) => $guid ? substr(sha1('mc-pop:'.$guid), 0, 12) : null;
        $winner = $a['won'] ? 'us' : 'them';

        $players = [];
        foreach ($a['players'] as $p) {
            $g = $p['guid'];
            $b = $a['breakdown'][$g] ?? [];
            $h = $a['habits'][$g] ?? [];
            $sum = fn (string $kind) => array_sum(array_column($b[$kind] ?? [], 'amount'));
            $players[$hash($g)] = [
                'spec' => (int) ($p['specExternalId'] ?? 0),
                'side' => $p['side'],
                'healer' => (bool) $p['healer'],
                'logger' => (bool) $p['logger'],
                'won' => $p['side'] === $winner,
                'alive' => $b['alive'] ?? 0,
                'idle' => $b['idle'] ?? 0,
                'free' => $h['free'] ?? 0,
                'locked' => $a['lockout'][$g] ?? 0,
                'damage' => $sum('damage'),
                'healing' => $sum('healing') + $sum('absorbs'),
                'casts' => $h['casts'] ?? [],
                'control' => $h['control'] ?? 0,
                'offTarget' => $h['offTarget'] ?? 0,
                'kicks' => $h['kicks'] ?? 0,
                'kicked' => array_sum($h['kicked'] ?? []),
                // Casts worth a kick met with the kick ready (version 15); null without an interrupt.
                'priority' => isset($h['priority']) ? [
                    'chances' => $h['priority']['chances'], 'mine' => $h['priority']['mine'], 'mate' => $h['priority']['mate'],
                    'landed' => $h['priority']['landed'], 'high' => $h['priority']['high'],
                ] : null,
                'petHits' => $h['petHits'] ?? 0,
                'petOnTarget' => $h['petOnTarget'] ?? 0,
                'ilvl' => $p['ilvl'] ?? null,
                'pvpTalents' => $p['pvpTalents'] ?? [],
                'failed' => $p['logger'] ? array_map(fn ($r) => $r['n'], $h['failed'] ?? []) : null,
                'died' => collect($a['deaths'])->contains('who', $g),
                'diedFirst' => ($a['deaths'][0]['who'] ?? null) === $g,
            ];
        }

        $goes = array_map(function ($go) use ($a, $hash) {
            // The defending side's answers pressed inside this go, for tools/match-review/decisions.php
            // (situation, choice, outcome; 2026-10-10). Rows end at the first death, as stored.
            $defending = $go['side'] === 'us' ? 'them' : 'us';
            $answers = [];
            foreach ($a['defensives'][$defending]['rows'] ?? [] as $r) {
                if ($r['t'] >= $go['from'] && $r['t'] <= $go['to']) {
                    $answers[] = [
                        't' => round((float) $r['t'] - (float) $go['from'], 1),
                        'spell' => $r['spell'],
                        'who' => $hash($r['who']),
                        'onTarget' => ($r['on'] ?? $r['who']) === $go['target'],
                        'hp' => $r['hp'] ?? null,
                        'ttl' => $r['ttl'] ?? null,
                        'needed' => (bool) ($r['needed'] ?? false),
                        'danger' => in_array('danger', $r['reasons'] ?? [], true),
                    ];
                }
            }

            // When each player's first offensive cooldown of the go went out: their spread is how
            // far apart "together" was.
            $firsts = [];
            foreach ($go['links'] ?? [] as $l) {
                if (in_array($l['cat'], ['offensive', 'mixed'], true) && $l['by'] && ! isset($firsts[$l['by']])) {
                    $firsts[$l['by']] = (float) $l['t'];
                }
            }

            return [
                'side' => $go['side'],
                // When it started and how long it ran: later goes meet more drained defensives and
                // dampening, and longer goes have more time to kill, so both are confounds to hold.
                'from' => round((float) $go['from'], 1),
                'len' => round((float) $go['to'] - (float) $go['from'], 1),
                'peakDamage' => (int) ($go['peak']['damage'] ?? 0),
                'good' => (bool) $go['good'],
                'healerCc' => (int) $go['healerCc'],
                'pressers' => count($firsts),
                'spread' => count($firsts) >= 2 ? round(max($firsts) - min($firsts), 1) : null,
                'joint' => (bool) ($go['peak']['joint'] ?? false),
                'healerLocked' => (float) ($go['peak']['healerLocked'] ?? 0),
                'healerKicked' => (bool) ($go['peak']['healerKicked'] ?? false),
                'drained' => (int) ($go['drained'] ?? 0),
                'defs' => (int) ($go['defs'] ?? 0),
                'kill' => $go['kill'] !== null,
                'killLater' => (bool) $go['killLater'],
                'target' => $hash($go['target'] ?? null),
                // The defending side's answers back as the go started (the cooldown ledger), and
                // what they pressed inside it.
                'cover' => isset($go['cover']) ? ['answers' => array_map(fn ($x) => ['who' => $hash($x['who'])] + $x, $go['cover']['answers'] ?? [])] + $go['cover'] : null,
                'targetHp' => $go['targetHp'] ?? null,
                'answers' => $answers,
            ];
        }, $a['goes']);

        return [
            'version' => $a['version'],
            'id' => $id,
            'bracket' => $meta['startInfo']['bracket'] ?? null,
            'playedAt' => isset($meta['startTime']) ? date('Y-m-d', (int) ($meta['startTime'] / 1000)) : null,
            'duration' => (float) ($meta['durationInSeconds'] ?? 0),
            'mmr' => $a['mmr'],
            // Defensive rows stop here, so a go starting later says nothing about what was pressed.
            'firstDeath' => isset($a['deaths'][0]['t']) ? round((float) $a['deaths'][0]['t'], 1) : null,
            'players' => $players,
            'goes' => $goes,
            // Situation, choice, outcome for every enemy go (norms.json `decisions`).
            'decisions' => DefensiveDecisions::goRows($a),
        ];
    }
}
