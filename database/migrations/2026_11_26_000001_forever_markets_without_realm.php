<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WoW Forever's markets lose the realm (2026-11-26): "US/CAMWOWUS2Realm2PvP/Horde" becomes "US/Horde".
 * Forever has one auction house per region and faction; the client's realm names were shard labels,
 * and from 22 Nov it reports none (ForeverMarketService's class comment).
 *
 * Scans are renamed. Price readings are renamed, and where two old markets now share a key, item and
 * hour, they merge the way ForeverMarketService::merge() does: the scan with more auctions wins (a
 * later one breaks a tie) and `sources` adds up. Not reversible: the old keys aren't kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rename = fn (string $old): string => count($p = explode('/', $old)) === 3
            ? strtoupper($p[0]).'/'.ucfirst(strtolower($p[2]))
            : $old;

        foreach (DB::table('forever_scans')->distinct()->pluck('market') as $old) {
            if ($rename($old) !== $old) {
                DB::table('forever_scans')->where('market', $old)->update(['market' => $rename($old)]);
            }
        }

        // Biggest first, so the main market is a plain rename and the rest merge into it.
        $olds = DB::table('forever_prices')->select('market', DB::raw('COUNT(*) as n'))->groupBy('market')
            ->orderByDesc('n')->pluck('market')->filter(fn ($m) => $rename($m) !== $m);

        foreach ($olds as $old) {
            $new = $rename($old);
            if (! DB::table('forever_prices')->where('market', $new)->exists()) {
                DB::table('forever_prices')->where('market', $old)->update(['market' => $new]);

                continue;
            }
            DB::table('forever_prices')->where('market', $old)->orderBy('id')->chunkById(500, function ($rows) use ($new) {
                foreach ($rows as $row) {
                    $there = DB::table('forever_prices')
                        ->where(['market' => $new, 'item_id' => $row->item_id, 'hour' => $row->hour])->first();
                    if (! $there) {
                        DB::table('forever_prices')->where('id', $row->id)->update(['market' => $new]);

                        continue;
                    }
                    $sources = (int) $there->sources + (int) $row->sources;
                    $wins = $row->scan_auctions > $there->scan_auctions
                        || ($row->scan_auctions == $there->scan_auctions && $row->scanned_at > $there->scanned_at);
                    DB::table('forever_prices')->where('id', $there->id)->update($wins ? [
                        'scanned_at' => $row->scanned_at, 'min_unit' => $row->min_unit, 'quantity' => $row->quantity,
                        'expiring' => $row->expiring, 'scan_auctions' => $row->scan_auctions, 'sources' => $sources,
                    ] : ['sources' => $sources]);
                    DB::table('forever_prices')->where('id', $row->id)->delete();
                }
            });
        }
    }

    public function down(): void
    {
        // The realm isn't kept, so there is nothing to split back.
    }
};
