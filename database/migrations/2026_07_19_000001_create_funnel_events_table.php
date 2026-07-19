<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funnel_events', function (Blueprint $table) {
            $table->id();
            $table->string('guest_session_id')->nullable();
            $table->string('event');
            $table->foreignId('module_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['event', 'created_at']);
            $table->index(['guest_session_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funnel_events');
    }
};
