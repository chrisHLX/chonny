<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blizzard's Game Data API already returns a clean, pre-computed `display_row`/`display_col`
     * per node (integers) alongside the messy `raw_position_x`/`raw_position_y` we've imported
     * into pos_x/pos_y since day one — confirmed directly in the fetched data/talenttrees/*.json
     * files, never previously imported. talent-tree-grid.blade.php has been fuzzy-clustering the
     * raw coordinates into approximate row/col indices this whole time purely because this clean
     * version was sitting unused. Added 2026-08-27 to back the prerequisite/point-gate work (see
     * TalentSelectionService::pointsSpentInTree()/isNodeLocked()) — display_row is what a point
     * gate's threshold is actually checked against, and it needs to be the real value Blizzard
     * assigns, not a derived cluster index that could shift between imports.
     */
    public function up(): void
    {
        Schema::table('talent_nodes', function (Blueprint $table) {
            $table->unsignedInteger('display_row')->nullable()->after('pos_y');
            $table->unsignedInteger('display_col')->nullable()->after('display_row');
        });
    }

    public function down(): void
    {
        Schema::table('talent_nodes', function (Blueprint $table) {
            $table->dropColumn(['display_row', 'display_col']);
        });
    }
};
