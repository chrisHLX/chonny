<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_materials', function (Blueprint $table) {
            $table->id();
            $table->string('url')->unique();
            $table->enum('url_type', ['youtube', 'webpage']);
            $table->longText('raw_content')->nullable();
            $table->enum('fetch_status', ['pending', 'fetched', 'failed'])->default('pending');
            $table->timestamp('fetched_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_materials');
    }
};
