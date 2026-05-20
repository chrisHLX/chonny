<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pipeline_steps', function (Blueprint $table) {
            $table->id();

            $table->foreignId('pipeline_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name');   // 'suggestions', 'card_generation'
            $table->string('status'); // pending | running | completed | failed

            $table->string('job_id')->nullable(); // optional
            $table->text('error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['pipeline_id', 'status']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_steps');
    }
};
