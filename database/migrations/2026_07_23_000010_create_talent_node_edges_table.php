<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('talent_node_edges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_node_id')->constrained('talent_nodes')->cascadeOnDelete();
            $table->foreignId('to_node_id')->constrained('talent_nodes')->cascadeOnDelete();
            $table->string('edge_type')->default('unlocks');
            $table->timestamps();

            $table->unique(['from_node_id', 'to_node_id', 'edge_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('talent_node_edges');
    }
};
