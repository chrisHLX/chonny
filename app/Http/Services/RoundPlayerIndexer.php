<?php

namespace App\Http\Services;

use App\Models\ArenaRound;
use App\Models\ArenaRoundPlayer;

/**
 * A round's players into arena_round_players: who played, on which side, what spec, whether they
 * won, and what the round measured for them (damage, healing, damage taken, deaths). Replaces the
 * round's rows wholesale, so re-measuring a round re-indexes it.
 *
 * `won` is the player's own side's result: the round's `analysis.won` is the uploader's. `realm_key`
 * is RealmDirectory::key().
 */
class RoundPlayerIndexer
{
    public function index(ArenaRound $round): int
    {
        $p = $round->payload ?? [];
        $a = $p['analysis'] ?? [];
        $throughput = $p['throughput']['players'] ?? [];
        $won = isset($a['won']) ? (bool) $a['won'] : null;
        $seconds = (int) ($p['metadata']['durationInSeconds'] ?? 0);

        $rows = [];
        foreach ($a['players'] ?? [] as $pl) {
            [$name, $realm, $region] = array_pad(explode('-', (string) ($pl['name'] ?? ''), 3), 3, null);
            if (! $name || ! $realm || ! $region || empty($pl['guid'])) {
                continue;
            }

            $side = (string) ($pl['side'] ?? '');
            $t = $throughput[$pl['guid']] ?? [];

            $rows[] = [
                'arena_round_id' => $round->id,
                'user_id' => $round->user_id,
                'match_id' => (string) $round->match_id,
                'bracket' => (string) $round->bracket,
                'played_at' => $round->played_at,
                'guid' => mb_substr((string) $pl['guid'], 0, 48),
                'name' => mb_substr($name, 0, 32),
                'realm' => mb_substr($realm, 0, 64),
                'realm_key' => mb_substr(RealmDirectory::key($realm), 0, 64),
                'region' => strtolower($region),
                'spec_external_id' => isset($pl['specExternalId']) ? (int) $pl['specExternalId'] : null,
                'class_slug' => $pl['classSlug'] ?? null,
                'side' => mb_substr($side, 0, 8),
                'healer' => (bool) ($pl['healer'] ?? false),
                'won' => $won === null || ! in_array($side, ['us', 'them'], true) ? null : ($side === 'us' ? $won : ! $won),
                'mmr' => isset($a['mmr'][$side]) ? (int) $a['mmr'][$side] : null,
                'ilvl' => isset($pl['ilvl']) ? (int) $pl['ilvl'] : null,
                'damage' => (int) ($t['damageDone'] ?? 0),
                'healing' => (int) ($t['healingEffective'] ?? 0),
                'damage_taken' => (int) ($t['damageTaken'] ?? 0),
                'deaths' => min(255, (int) ($t['deaths'] ?? 0)),
                'seconds' => $seconds,
                'pvp_talents' => json_encode(array_values($pl['pvpTalents'] ?? [])),
            ];
        }

        ArenaRoundPlayer::where('arena_round_id', $round->id)->delete();
        if ($rows !== []) {
            ArenaRoundPlayer::insert($rows);
        }

        return count($rows);
    }
}
