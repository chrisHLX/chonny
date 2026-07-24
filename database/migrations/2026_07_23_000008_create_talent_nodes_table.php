<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('talent_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('talent_tree_id')->constrained()->cascadeOnDelete();
            // Blizzard's node id — required to resolve locked_by/unlocks edges on import; not
            // this table's PK since it's only unique within a single talent tree, not globally.
            $table->unsignedInteger('external_node_id');
            $table->string('type');
            $table->integer('pos_x')->nullable();
            $table->integer('pos_y')->nullable();
            $table->unsignedInteger('max_ranks')->default(1);
            $table->timestamps();

            $table->unique(['talent_tree_id', 'external_node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('talent_nodes');
    }
};
