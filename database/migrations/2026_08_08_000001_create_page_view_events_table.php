<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_view_events', function (Blueprint $table) {
            $table->id();
            $table->string('page'); // 'wow_comps' | 'spell_explorer' — extend as more pages get tracked
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->foreignId('spec_id')->nullable()->constrained('specializations')->nullOnDelete();
            // Which comp slot this selection was made in (WowComps only — '0'/'1'/'2'), null for
            // a bare page-view row or for SpellExplorer (single-picker, no slot concept).
            $table->string('slot')->nullable();
            $table->string('session_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['page', 'created_at']);
            $table->index(['page', 'class_id']);
            $table->index(['page', 'spec_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_view_events');
    }
};
